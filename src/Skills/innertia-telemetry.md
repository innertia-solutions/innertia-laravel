---
name: innertia-telemetry
description: Use when working with the self-contained observability engine — trace_id per request, error cases by fingerprint, logs, mail log, presence, support email, and the MCP server. Trigger for "telemetría", "TelemetryTrace", "TelemetryCase", "trace_id", "casos de error", "telemetry:prune", "MCP innertia", "review_case prompt", "usuarios conectados", "beacon de errores de cliente".
---

# Innertia — Telemetry (motor de observabilidad self-contained)

Cada producto persiste su propia telemetría en su MISMA base de datos (sin Olimpo, sin servicio externo). Un error deja de ser una línea de log: se agrupa en un **caso** por fingerprint con todo su contexto (trace del request, payload, vista, response, logs, usuario, tenant). Se expone por un servidor **MCP** para que una IA lo lea, y notifica a soporte por correo cuando nace un caso.

## Activación

```env
TELEMETRY_ENABLED=true              # master switch (default false)
TELEMETRY_SUPPORT_EMAIL=soporte@tu-producto.com
TELEMETRY_RETENTION_DAYS=7          # traces + events
TELEMETRY_CASE_RETENTION_DAYS=90    # casos (histórico agregado)
TELEMETRY_MCP_ENABLED=true          # servidor MCP (stdio)
TELEMETRY_MCP_WEB=false             # además exponer MCP por HTTP en /platform/mcp
TELEMETRY_PRESENCE_ENABLED=true
TELEMETRY_PRESENCE_TTL=60           # segundos
```

En local/testing basta `TELEMETRY_ENABLED=true`. En otros entornos, además de `enabled`, el usuario autenticado necesita permiso `devtools`. Las tablas y las rutas se cargan SIEMPRE (independiente del gate de captura), así que el MCP y el histórico funcionan aunque la captura runtime esté apagada.

## Qué captura

Por request, todo se etiqueta con un `trace_id` (nanoid) generado en `TelemetryCollector`:

| Tipo | Fuente |
|---|---|
| `request` | middleware `TelemetryMiddleware` (método, ruta, url, status, duración, ip, ua, payload, response) |
| `query` | `DB::listen` |
| `log` | `Log::listen` |
| `event` | `Event::listen('*')` (ignora prefijos de framework) |
| `datatable` | hook `DataTable::$onRender` |
| `exception` | extiende el `ExceptionHandler` |
| `mail` | listener `MessageSending` |
| `client` | beacon `POST /platform/telemetry/client` (errores JS/fetch del front) |

`config('innertia.telemetry.capture.*')` activa/desactiva cada colector. Los payloads se redactan con `PayloadSanitizer` según `config('innertia.telemetry.redact')` (password, token, secret, authorization, ...).

## Modelo de datos (tablas en la DB del producto)

| Tabla / Modelo | Rol |
|---|---|
| `telemetry_traces` / `TelemetryTrace` | snapshot del request (contexto completo) |
| `telemetry_events` / `TelemetryEvent` | cada evento colgado de `trace_id` |
| `telemetry_cases` / `TelemetryCase` | grupo por `fingerprint` (times_seen, first/last_seen, status open/resolved/ignored, sample_trace_ids, notified_at) |
| `telemetry_presence` / `TelemetryPresence` | usuarios conectados (heartbeat, purga por TTL) |

**Casos:** el `CaseRecorder` calcula el fingerprint (`CaseFingerprinter`: clase+archivo+línea+mensaje normalizado — ignora números/uuids/strings), hace upsert (nuevo / recurrencia `times_seen++` / reapertura si estaba resuelto), y notifica a soporte SOLO en nuevo o reabierto. La idempotencia del correo es una **reclamación atómica** sobre `notified_at` (whereNull→now); al reabrir se resetea `notified_at` para un nuevo episodio.

## Endpoints (frontend)

- `POST /platform/telemetry/presence` — heartbeat `{ view }` (upsert por session).
- `POST /platform/telemetry/client` — beacon de error de cliente `{ view, action, message, context }`.

El composable `usePresence()` de innertia-nuxt los consume (fuera de este paquete).

## MCP

Servidor `Innertia\Telemetry\Mcp\ObservabilityServer` (handle local `innertia`), expuesto por stdio y, si `mcp.web=true`, por HTTP en `/platform/mcp`.

Registrar el MCP local en tu `.mcp.json` de Claude Code:

```json
{
  "mcpServers": {
    "innertia": { "command": "php", "args": ["artisan", "mcp:start", "innertia"] }
  }
}
```

**Tools** (todas read-only): `list-cases`, `get-case`, `search-logs`, `get-trace`, `who-is-online`, `email-log`, `server-health`.
**Prompt** `review-case` (`ReviewCasePrompt`): arma el prompt de revisión de un caso con `ReviewPromptBuilder` (el mismo texto que se embebe en el correo a soporte).

## Retención

```bash
php artisan telemetry:prune          # traces/events > retention_days, casos > case_retention_days, presence > ttl
php artisan telemetry:prune --days=3 # override de retention_days (traces/events)
```

Programar en el scheduler del producto (diario).

## Notas

- `laravel/mcp` es dependencia del paquete; en apps se auto-descubre. En el Testbench de la lib se registra a mano en `tests/Pest.php`.
- El servidor MCP y el histórico NO dependen del gate de captura; el `enabled=false` solo apaga la captura runtime, no las tablas ni las rutas.
