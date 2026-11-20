# Facturación (`Innertia\Billing`, etapa 2: facturación base)

Planes y precios, suscripción por espacio, medidores de consumo (incluida la IA a costo + margen), cierre
mensual, facturas por espacio o consolidadas por cliente, emisión con PDF y correo, pago por enlace o
transferencia, mora y suspensión. Implementa el spec `2026-11-18-clientes-espacios-facturacion-design.md`
(§4.2, §5, §6.3–6.5, §7, §8). La cobranza (ajustes desde platform, notas de crédito, interés por mora,
refacturación, conciliación, estado de cuenta, recordatorios, reportes) es la etapa 3.

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

Requiere clients (modos saas/open). Con el feature activo la lib carga sola la migración
`database/migrations/billing` (11 tablas `billing_*`, idempotente) — `php artisan migrate` — agenda el
cierre (`innertia:billing:close`, 00:30) y la mora (`innertia:billing:dunning`, 08:00) en hora de Chile
(`withoutOverlapping` + `onOneServer`; requiere `schedule:work`), escucha `AiUsageRecorded` y
`PaymentPaid`, registra los tipos de aviso `billing.*` y el alias de middleware `billing.enforce`.
Sin el flag nada de esto existe (sumando, gym, asetio no cambian).

Config (`innertia.billing.*`): `currency`, `timezone`, `meters` (null = todos), `default_plan`,
`close_at`, `dunning_at`, `payment_provider` (`flow`|`mercadopago`|null), `usd_clp_rate` (respaldo),
`suspended_allow`, `number_prefix`, `backoffice_path`, `invoice_view`.
Editables en platform (Settings de plataforma, `GET|PUT billing/settings`): `usd_clp_rate`,
`transfer_instructions`, `issuer_name`, `issuer_tax_id`, `issuer_address`.

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
| `GET|PUT billing/settings` · `POST billing/run/{close|dunning}` | |

| Backoffice | |
|---|---|
| `GET billing/overview` | plan, estado, consumo vs incluido, estimado, saldo (`{testing: true}` en pruebas) |
| `GET billing/invoices` (+ `space_part` en consolidadas) · `GET billing/invoices/{id}` · `GET …/pdf` | solo las del espacio |
| `GET billing/payments` | pagos aplicados a sus facturas |

## 5. Front

innertia-nuxt: `Billing.Plans`, `Billing.Invoices`, `Billing.SpaceSubscription`, `Billing.ClientTotals`,
`Billing.Settings` (platform) y `Billing.Overview`, `Billing.StatusBanner` (backoffice). Ver
`docs/billing.md` de innertia-nuxt.

## 6. Adopción (checklist del producto)

1. Flags `INNERTIA_CLIENTS_ENABLED` + `INNERTIA_BILLING_ENABLED`, `php artisan migrate`, `schedule:work`.
2. `innertia.billing.meters` con los medidores que usa; `Meters::record()` en los puntos de consumo.
3. `SeatCounter` propio si "usuario facturable" no es "usuario con contexto en el espacio".
4. Permiso `billing.view` para el admin del espacio; rutas y middleware `billing.enforce` en backoffice.
5. Planes en platform; asignar suscripción a cada espacio de producción.
6. Opcional: `payment_provider` + credenciales de plataforma en `Innertia\Payments` para el enlace de pago.
