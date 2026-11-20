# Facturación (`Innertia\Billing`, etapas 2 y 3: facturación base y cobranza)

Planes y precios, suscripción por espacio, medidores de consumo (incluida la IA a costo + margen), cierre
mensual, facturas por espacio o consolidadas por cliente, emisión con PDF y correo, pago por enlace o
transferencia, mora y suspensión. Implementa el spec `2026-11-18-clientes-espacios-facturacion-design.md`
(§4.2, §5, §6.3–6.6, §7, §8). La cobranza (etapa 3: ajustes y notas de crédito, descuentos, interés por
mora, refacturación con bitácora, conciliación de cartolas, saldo a favor, estado de cuenta, recordatorios y
reportes) está en la sección 7.

## Decisiones

| Tema | Decisión |
|---|---|
| Dinero | **Enteros en la unidad mínima de la moneda** (`bigint`). CLP no tiene decimales: 119000 = $119.000. Cada línea se redondea (half up); el IVA se redondea una vez sobre el subtotal. |
| Zona horaria | `innertia.billing.timezone` = `America/Santiago`. Fechas de período = fechas locales (`Y-m-d`), ambos extremos incluidos. Los timestamps (consumo, emisión) se guardan en la zona de la app (UTC) y se convierten con `BillingClock`. |
| Períodos | Aniversario de la fecha de inicio (15-sep → 14-oct). Mensual o anual. Sin prorrateo; el cambio de plan rige desde el período siguiente (`pending_plan_id`). |
| Trial | El período de prueba no se cobra (ni su consumo); el primer período pagado parte al día siguiente. |
| Suspensión | Vive en la suscripción (`suspended`), **no** toca `tenants.status`: un espacio `inactive` sigue gobernado por el producto. Suspendido = solo lectura (402 en escrituras). |
| Consolidada | Los cobros cerrados de los espacios del cliente se suman al borrador abierto del cliente hasta que platform lo emite. |
| Espacios de pruebas | Sin suscripción, nunca se cobran; uno de producción con cobros no vuelve a pruebas. |
| Numeración | Interna, correlativa al emitir (`F-000001`, `innertia.billing.number_prefix`). El folio SII se adjunta a mano. |

## 1. Activar

```env
INNERTIA_CLIENTS_ENABLED=true
INNERTIA_BILLING_ENABLED=true
```

Requiere clients (modos saas/open). Con el feature activo la lib carga sola las migraciones de
`database/migrations/billing` (17 tablas `billing_*`, idempotentes: crean lo que falta y agregan columnas
faltantes, así una base de la etapa 2 sube en su lugar) — `php artisan migrate` — agenda el
cierre (`innertia:billing:close`, 00:30), la mora (`innertia:billing:dunning`, 08:00) y los recordatorios
(`innertia:billing:reminders`, 09:00, a la cola `innertia.billing.queue`) en hora de Chile
(`withoutOverlapping` + `onOneServer`; requiere `schedule:work`), escucha `AiUsageRecorded` y
`PaymentPaid`, registra los tipos de aviso `billing.*` y el alias de middleware `billing.enforce`.
Sin el flag nada de esto existe (sumando, gym, asetio no cambian).

Config (`innertia.billing.*`): `currency`, `timezone`, `meters` (null = todos), `default_plan`,
`close_at`, `dunning_at`, `payment_provider` (`flow`|`mercadopago`|null), `usd_clp_rate` (respaldo),
`suspended_allow`, `number_prefix`, `backoffice_path`, `invoice_view`, `queue`, `credit_note_prefix`,
`reminders_at`, `reminders.*`, `reconciliation.{auto_score,min_score}`.
Editables en platform (Settings de plataforma, `GET|PUT billing/settings`): `usd_clp_rate`,
`transfer_instructions`, `issuer_name`, `issuer_tax_id`, `issuer_address` y los recordatorios
(`reminders_enabled`, `reminder_days_before`, `reminder_on_due`, `reminder_overdue_every_days`,
`reminder_overdue_max`, `reminder_escalate_after`, `reminder_escalation_email`).

## 2. Medidores

Catálogo estándar: `ai.cost` (valorizado por costo), `whatsapp.messages`, `sms.messages`,
`voice.minutes`, `emails.sent`, `storage.gb` (máximo), `users.active` (máximo).

```php
use Innertia\Billing\{Billing, Meters, MeterDefinition};

// Al terminar la llamada / enviar el mensaje (idempotente por sourceRef):
Meters::record($tenant, Meters::VOICE_MINUTES, $billableMinutes, $endedAt, ['call_id' => $id], sourceRef: "call:{$id}");
Billing::record('whatsapp.messages', 1, "wa:{$messageId}");          // tenant activo

// Medidor propio y cálculo propio:
Meters::register(new MeterDefinition('pages.printed', 'Páginas impresas', 'página'));
Meters::pricer('voice.minutes', new MyVoicePricer);                  // implements Contracts\MeterPricer

// Usuarios: el producto define qué es un usuario facturable (default: usuarios con contexto en el espacio).
$this->app->bind(\Innertia\Billing\Contracts\SeatCounter::class, App\Billing\ActiveStaffCounter::class);
```

- `record()` es no-op con el feature apagado; un medidor conocido pero no activado (`innertia.billing.meters`)
  se ignora; uno desconocido lanza `InvalidArgumentException`.
- La IA se mide sola: cada `Ai::recordUsage()` con costo y espacio → `ai.cost` (USD y CLP al tipo de cambio).
- Precio por plan (`billing_plan_meters`): cantidad → `(consumo − incluido) × unit_price`;
  costo → `costo × (1 + markup%) − incluido` (incluido como monto). Consumo registrado después del cierre
  entra como `late_usage` en el cobro siguiente, sin incluido.

## 3. Flujos

- **Suscripción** (`StartSubscription`): espacio de producción, plan activo, `payment_method`
  (`automatic` = enlace de pago, `invoice` = pago registrado a mano), `overrides` (precios negociados:
  `base_price`, `seat_price`, `included_seats`, `meters.{clave}.{unit_price|included_quantity|markup_percent}`),
  trial opcional. Autoregistro (`CreateOpenTenant`) con `default_plan` → trial automático.
- **Cierre** (`CloseBillingPeriods` → `CloseSubscriptionPeriod`): por cada período vencido arma el cobro
  (base, usuarios adicionales, medidores, consumo tardío, ajustes vigentes), IVA del plan, marca el consumo,
  avanza el período y aplica el plan pendiente; agrupa en factura borrador. Bloqueo por suscripción +
  `unique(tenant_id, period_start)`: correrlo dos veces no duplica nada. Recupera días perdidos.
- **Emisión** (`IssueInvoice`): número, snapshot de datos de facturación (los del espacio sobre los del
  cliente en `per_space`), vence a `payment_terms_days`, aplica saldo a favor, enlace de pago si
  corresponde, correo al `billing_email` con el PDF (marca de plataforma) y aviso a los admins del espacio.
- **Pago**: `RegisterPayment` (transferencia a mano; parcial, uno para varias facturas, el resto queda como
  saldo a favor). Enlace: `Innertia\Payments` con credenciales de **plataforma** (`payment_provider`);
  el webhook `PaymentPaid` registra y aplica el pago una sola vez (idempotente por charge).
- **Mora** (`RunDunning`, diario): factura vencida → `overdue`; suscripción → `past_due`; vencida más de
  `grace_days` → `suspended`; sin facturas vencidas → `active` (salvo suspensión manual). Platform puede
  suspender, reactivar o cancelar a mano. Cada cambio deja log con `trace_id`, evento
  `SubscriptionStatusChanged` y aviso (`billing.past_due`, `billing.suspended` son críticos).

Eventos: `InvoiceIssued`, `InvoicePaid`, `SubscriptionStatusChanged`. Bitácora por factura en
`billing_invoice_events`.

## 4. Rutas (sin auth propia)

```php
// grupo platform
\Innertia\Billing\Routes::platform();                                             // lectura (owner y staff)
Route::middleware('platform.owner')->group(fn () => \Innertia\Billing\Routes::platformWrite());

// grupo backoffice (tenant): solo lectura del propio espacio
Route::middleware('permission:billing.view')->group(fn () => \Innertia\Billing\Routes::backoffice());
// y el modo solo lectura en el grupo backoffice completo:
Route::middleware(['billing.enforce'])->group(...);
```

| Platform | |
|---|---|
| `GET billing/plans` · `POST billing/plans` · `PATCH billing/plans/{id}` | planes con `meters[]` |
| `GET billing/meters` | catálogo activo |
| `GET billing/spaces/{key}` | suscripción, plan, consumo del período con estimado, usuarios, cobros |
| `POST billing/spaces/{key}/subscription` · `PATCH …` · `POST …/{suspend|reactivate|cancel}` | |
| `GET billing/invoices?status=&client_id=&tenant_key=&page=` · `GET billing/invoices/{id}` | detalle con cobros, líneas, bitácora |
| `POST billing/invoices/{id}/issue {folio?, pdf_file_id?, send?}` · `POST …/resend` · `GET …/pdf` | |
| `GET billing/payments?client_id=` · `POST billing/payments {client_id, amount, reference?, received_at?, invoice_id?}` | |
| `GET billing/clients/totals[?client_id=]` | MRR, saldo, vencido y espacios por cliente |
| `GET|PUT billing/settings` · `POST billing/run/{close|dunning|reminders}` | |
| Cobranza (etapa 3): ver sección 7 | |

| Backoffice | |
|---|---|
| `GET billing/overview` | plan, estado, consumo vs incluido, estimado, saldo (`{testing: true}` en pruebas) |
| `GET billing/invoices` (+ `space_part` en consolidadas) · `GET billing/invoices/{id}` · `GET …/pdf` | solo las del espacio |
| `GET billing/payments` | pagos aplicados a sus facturas |
| `GET billing/account?from=&to=` | estado de cuenta del espacio (sus facturas y lo aplicado a ellas, antigüedad) |

## 5. Front

innertia-nuxt: `Billing.Plans`, `Billing.Invoices`, `Billing.SpaceSubscription`, `Billing.ClientTotals`,
`Billing.Settings`, y de cobranza `Billing.Adjustments`, `Billing.CreditNoteForm`, `Billing.ClientTerms`,
`Billing.AccountStatement`, `Billing.Reconciliation`, `Billing.Reports`, `Billing.ActivityLog` (platform) y
`Billing.Overview`, `Billing.StatusBanner`, `Billing.AccountStatement` (backoffice). Ver
`docs/billing.md` de innertia-nuxt.

## 6. Adopción (checklist del producto)

1. Flags `INNERTIA_CLIENTS_ENABLED` + `INNERTIA_BILLING_ENABLED`, `php artisan migrate`, `schedule:work`.
2. `innertia.billing.meters` con los medidores que usa; `Meters::record()` en los puntos de consumo.
3. `SeatCounter` propio si "usuario facturable" no es "usuario con contexto en el espacio".
4. Permiso `billing.view` para el admin del espacio; rutas y middleware `billing.enforce` en backoffice.
5. Planes en platform; asignar suscripción a cada espacio de producción.
6. Opcional: `payment_provider` + credenciales de plataforma en `Innertia\Payments` para el enlace de pago.

## 7. Cobranza (etapa 3)

Todo vive en el mismo feature (`innertia.billing.enabled`); las tablas nuevas las agrega la migración
`2026_11_20_100001_create_billing_collections_tables.php`: `billing_client_terms`, `billing_late_fees`,
`billing_bank_imports`, `billing_bank_transactions`, `billing_reminders`, `billing_activity` y columnas en
`billing_adjustments` (cliente, nota de crédito, anulación), `billing_invoices` (anulación, reemplazo,
condonación) y `billing_payments` (`bank_transaction_id` único).

| Tema | Decisión |
|---|---|
| Ajustes | Por espacio: descuento, recargo, cargo único o crédito; monto neto o % sobre el cobro antes de ajustes; recurrente con vigencia (`starts_at`..`ends_at`) o de una vez. Por cliente: **solo descuentos/recargos porcentuales**, aplican a cada espacio. Motivo y autor obligatorios; se anulan mientras no se apliquen. |
| Nota de crédito | `NC-000001` con motivo y autor. Destino `invoice` (monto con IVA, a una factura abierta: pago no monetario `method=credit`; lo que sobra queda a favor), `balance` (saldo a favor, se aplica en la próxima emisión) o `next_period` (línea neta en el próximo cobro del espacio). |
| Interés por mora | `clients.late_fee_percent` (0 = no) con base `monthly` (÷30 por día) o `daily`, tope % sobre el total de la factura y exención por cliente (`billing_client_terms`). Se cobra **al cierre** como línea `late_fee` del espacio: saldo vencido × tasa diaria × días desde el día siguiente al vencimiento (o al último tramo cobrado) hasta el fin del período. En consolidadas, cada espacio paga sobre su parte. Afecto a IVA. |
| Condonar | `waive-late-fees` con motivo: no corre más interés para esa factura; el ya cargado se quita del borrador o se devuelve con nota de crédito si ya se emitió. Bitácora `late_fee_waived`. |
| Refacturar | Una factura emitida no se edita: `reissue` la anula (motivo obligatorio) y emite otra (`replaces_invoice_id`) con los mismos cobros, correcciones de datos de facturación y líneas de corrección (netas, con signo). Pagos y notas de crédito pasan a la nueva; el exceso vuelve como saldo a favor. Mantiene el vencimiento original salvo `due_at`. Bitácora: `voided` y `reissued` con quién, cuándo, motivo y diferencias (datos, totales, líneas). `void` anula sin reemplazo (lo pagado queda a favor). |
| Conciliación | Cartola CSV (`;` `,` tab; Latin-1 se convierte) o XLSX, con mapeo de columnas (`date`, `amount` o `credit`+`debit`, `description`, `reference`, `tax_id`, `name`); la fila de encabezados se busca bajo los títulos del banco. La importación corre en cola (`ImportBankStatement`, `$timeout` 600). **Idempotente**: el mismo archivo devuelve la misma importación y cada movimiento tiene un hash único (fecha, monto, glosa, referencia, RUT y ocurrencia), así cartolas solapadas no duplican. Solo abonos. |
| Emparejamiento | Puntaje 0–100 por RUT (40; en la glosa 35), número/folio de factura (40), nombre (15–20) y monto (= saldo 45, = suma de varias 40, = total 25, parcial 5). Se aplica solo con ≥ `auto_score` (85) y 15 puntos sobre el segundo; si no queda sugerido. Manual: cliente + facturas en orden (pago parcial, varias facturas, el resto a favor). Se puede deshacer (`unmatch`, reabre las facturas) o descartar. |
| Estado de cuenta | Cargos (facturas emitidas), abonos (pagos, notas de crédito, anulaciones) y saldo corrido por cliente (o por espacio en el backoffice), saldo inicial con `from`, saldo a favor disponible y antigüedad: por vencer, 0–30, 31–60, 61–90, +90 días. Export xlsx. |
| Recordatorios | Diarios: N días antes (`reminder_days_before`, ej. "7,3"), el día que vence y, vencida, al día siguiente y cada `reminder_overdue_every_days` hasta `reminder_overdue_max`; desde `reminder_escalate_after` se escala (copia a `reminder_escalation_email` + aviso crítico `billing.collection_escalated`). Correo con marca de plataforma + aviso `billing.payment_reminder` a los admins. Único por (factura, tipo, secuencia): nunca repite. Apagables por cliente. No recupera días perdidos. |
| Reportes | `revenue` (facturado por mes/cliente/plan/producto), `receivables`, `aging`, `collections` (recaudación por mes/medio/cliente; las notas de crédito no son dinero), `mrr` (por cliente/plan/producto), `usage` (consumo por espacio y medidor). JSON y xlsx. |
| Auditoría | `billing_activity` (quién, qué, datos, `trace_id`) para ajustes, notas de crédito, condiciones, anulaciones, refacturaciones, conciliaciones y saldo a favor; la bitácora por factura sigue en `billing_invoice_events`. Todo log operativo va a Telemetry (módulo `billing`) con `trace_id`. |

### Endpoints de cobranza (platform)

Lectura (`Routes::platform()`):

| | |
|---|---|
| `GET billing/adjustments?client_id=&tenant_key=&credit_notes=&status=pending|applied|canceled` | ajustes y notas de crédito (los del cliente aparecen con su espacio) |
| `GET billing/clients/{id}/terms` | condiciones de cobranza |
| `GET billing/clients/{id}/account?from=&to=&tenant_key=` · `GET …/account/export` | estado de cuenta (+ xlsx) |
| `GET billing/activity?client_id=&subject_type=` | bitácora de cobranza |
| `GET billing/reconciliation/imports` · `GET …/imports/{id}` | importaciones (estado, contadores) |
| `GET billing/reconciliation/transactions?status=open|unmatched|suggested|matched|ignored&import_id=&client_id=` | movimientos con sugerencias (+ `summary` pendientes) |
| `GET billing/reconciliation/open-invoices?client_id=&q=` | facturas abiertas para emparejar a mano |
| `GET billing/reports/{revenue|receivables|aging|collections|mrr|usage}?from=&to=&group_by=` · `GET …/export` | reportes (+ xlsx) |

Escritura (`Routes::platformWrite()`, owner):

| | |
|---|---|
| `POST billing/adjustments {tenant_key | client_id, type, amount | percent, recurring?, starts_at?, ends_at?, reason}` · `POST billing/adjustments/{id}/cancel {reason}` | |
| `POST billing/credit-notes {client_id, amount, reason, target: invoice|balance|next_period, invoice_id?, tenant_key?}` | |
| `PUT billing/clients/{id}/terms {late_fee_percent?, late_fee_basis?, late_fee_cap_percent?, late_fee_exempt?, reminders_enabled?}` | |
| `POST billing/clients/{id}/apply-credit {invoice_id?}` | aplica el saldo a favor ahora |
| `POST billing/invoices/{id}/void {reason}` · `POST …/reissue {reason, billing_data?, refresh_billing_data?, lines?, due_at?, folio?, send?}` · `POST …/waive-late-fees {reason}` | |
| `POST billing/reconciliation/preview` (multipart `file`) | encabezados, muestra y mapeo sugerido |
| `POST billing/reconciliation/imports` (multipart `file`, `mapping` objeto o JSON, `bank?`, `header_row?`) | 201 nueva · 200 `{duplicate: true}` |
| `POST billing/reconciliation/rematch {import_id?}` | vuelve a puntuar los pendientes |
| `POST billing/reconciliation/transactions/{id}/match {client_id, invoice_ids[]}` · `…/unmatch {reason}` · `…/ignore {reason?}` · `…/restore` | |

Use cases públicos: `CreateAdjustment`, `CancelAdjustment`, `CreateCreditNote`, `VoidInvoice`, `ReissueInvoice`,
`WaiveLateFees`, `SaveClientTerms`, `ImportBankStatement`, `MatchBankTransactions`, `ReconcileTransaction`,
`UnmatchTransaction`, `IgnoreTransaction`, `SendPaymentReminders`; soporte `AccountStatement`,
`BillingReports`, `LateFeeCalculator`, `TransactionMatcher`, `BankStatementReader`.

Fuera de esta etapa: SII (anular = nota de crédito electrónica), cargo automático a tarjeta, prorrateo.
