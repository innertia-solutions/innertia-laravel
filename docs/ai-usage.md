# Consumo de IA con costo (`Innertia\Ai`, fase C1)

Registro del consumo de IA por espacio, sede y función, con su costo en USD calculado con el precio
vigente del modelo. Es la base para cobrar la IA a costo real + margen (medidor `ai.cost` de
`Innertia\Billing`, ver `docs/billing.md`).

**Compatible hacia atrás:** `Ai::usage()->record()` sigue igual (ahora además guarda tenant y costo de
tokens). Los registros viejos siguen válidos: columnas nuevas nulas, se agrupan por `use` y cuentan como
"sin precio". Si el producto aún no migró, el logger solo escribe las columnas que existen.

## 1. Esquema

Con `innertia.ai.enabled` la lib carga una migración aditiva e idempotente
(`database/migrations/ai/2026_11_19_000010_add_ai_usage_cost_columns.php`):

| Tabla | Columnas nuevas |
|---|---|
| `ai_usage_events` | `tenant_id` (string), `organization_id` (string), `feature`, `units` (json), `cost_usd` (decimal 16,8) |
| `ai_models` | `price_input_per_mtok`, `price_output_per_mtok`, `price_per_audio_minute`, `price_per_mchar_tts` (USD, null = sin precio) |

Si las tablas `ai_*` todavía no existen la migración no hace nada (el stub de `innertia:ai:install` ya
trae las columnas). Backfill: `tenant_id` de los eventos de usuarios que pertenecen a un solo espacio.

## 2. Registrar consumo

```php
use Innertia\Ai\Facades\Ai;

Ai::recordUsage('call_summary', ['input_tokens' => 1200, 'output_tokens' => 300], 'gpt-4.1-mini');
Ai::recordUsage('transcription', ['audio_seconds' => 95], 'whisper-1');
Ai::recordUsage('live_copilot', ['audio_seconds' => 600], $model, ['provider_id' => $providerId]);
Ai::recordUsage('tts', ['tts_chars' => 480], 'speech-02');
```

- `feature`: libre, convención `call_summary`, `transcription`, `live_copilot`, `assistant`, `tts`,
  `voice_agent`, `import_pdf`…
- `units`: `input_tokens`, `output_tokens`, `audio_seconds`, `tts_chars` (claves extra se guardan tal cual).
- `model`: key del modelo (o el `AiModel`); si hay dos con la misma key se prefiere el de `context.provider_id`.
- Tenant y organización: los activos (o `context.tenant_id` / `context.organization_id`, útil en jobs).
- Costo: `input/1M × price_input_per_mtok + output/1M × price_output_per_mtok + audio_seconds/60 ×
  price_per_audio_minute + tts_chars/1M × price_per_mchar_tts`. Se calcula al registrar y **no** se
  recalcula si cambia el precio. Modelo desconocido o sin precio → `cost_usd = null`.
- Cada registro dispara `Innertia\Ai\Events\AiUsageRecorded`.

## 3. Consultas agregadas

```php
$report = Ai::usageReport('2026-11-01', '2026-11-30', tenantId: '12');   // o UsageReport::month('2026-11', '12')
$report->totals();     // events, cost_usd, input_tokens, output_tokens, audio_seconds, tts_chars, unpriced_events
$report->byFeature();  // por COALESCE(feature, use)
$report->byModel();
$report->byTenant();
$report->daily();      // un registro por día del rango, días en America/Santiago (innertia.ai.usage_timezone)
$report->cost();       // USD del rango (para avisos de presupuesto)
```

## 4. Endpoints (sin auth propia: se montan dentro de los grupos del producto)

```php
// grupo platform
\Innertia\Ai\Routes::platform();                       // GET ai/models/prices · GET ai/usage/report
Route::middleware('platform.owner')->group(fn () => \Innertia\Ai\Routes::platformWrite());  // PUT ai/models/{id}/prices

// grupo backoffice (espacio activo)
\Innertia\Ai\Routes::tenant();                         // GET ai/usage/summary?month=YYYY-MM
```

| Endpoint | Respuesta |
|---|---|
| `GET ai/models/prices` | `{data: [{id, key, name, provider, prices{…4}, priced}], currency: 'USD'}` |
| `PUT ai/models/{id}/prices` | body con las 4 claves (null = sin precio) |
| `GET ai/usage/report?month=` o `?from=&to=` `[&tenant_id=]` | resumen + `by_tenant` (con `tenant_key`/`tenant_name`) si no se filtra por espacio |
| `GET ai/usage/summary?month=` | resumen del espacio activo |

No chocan con las rutas propias que ya tienen los productos (`ai/usage`, `ai/providers`…).

## 5. Facturación

Con `Innertia\Billing` activo, cada uso con costo y con espacio se registra como medidor `ai.cost`
(cantidad = USD, `cost` = CLP al tipo de cambio de plataforma `billing.usd_clp_rate`), idempotente por el
id del evento. El plan lo cobra como costo × (1 + margen %) menos lo incluido.

## 6. Front

innertia-nuxt: `Ai.ModelPrices` y `Ai.UsageReport` (platform, `/platform/ai`), `Ai.UsageSummary`
(backoffice, con presupuesto opcional). Ver `docs/ai-usage.md` de innertia-nuxt.
