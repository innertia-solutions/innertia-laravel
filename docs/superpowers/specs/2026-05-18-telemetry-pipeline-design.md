# Innertia Telemetry Pipeline — Design Spec

**Date:** 2026-05-18  
**Scope:** Pipeline A — Colección, transporte y recepción de telemetría (sin UI)  
**Paquete:** `innertia-laravel` (módulo `Telemetry`)

---

## Contexto

Olimpo es el hub central de operaciones del ecosistema Innertia. Tiene tres pilares:
1. Clientes, apps y facturación
2. Soporte, métricas por app y mantenimiento ← **aquí vive el devtools**
3. Administración de respaldos por app y tenant

Cada app construida con `innertia-laravel` debe poder enviar telemetría a Olimpo en tiempo real. La librería actúa en ambos roles: **sender** (en las apps cliente) y **receiver** (en Olimpo). Esto se decide por configuración, no por código distinto.

---

## Protocolo

**Inspiración:** OpenTelemetry (OTLP/HTTP) — mismo concepto de batches JSON por HTTP POST, pero con schema propio adaptado a Innertia.

**Por qué no OTEL completo:** OTEL no conoce DataTables, tenants, contextos ni permisos de Innertia. Un schema propio permite capturar exactamente lo que el framework produce sin adaptadores complejos.

---

## Arquitectura

```
App cliente (sender)                    Olimpo (receiver)
────────────────────────────────────────────────────────────────
Durante el request:
  DB::listen         ──→ TelemetryCollector (memoria)
  DataTable calls    ──→ TelemetryCollector
  Events             ──→ TelemetryCollector
  Logs               ──→ TelemetryCollector
  Exceptions         ──→ TelemetryCollector
        │
        ├── broadcast inmediato
        │   └── Soketi: private-innertia.devtools.{sessionId}
        │         └── (futuro innertia-nuxt-devtools)
        │
        └── acumula en batch
                ↓  (terminate() — fuera del response)
          TelemetryExporter
                ↓
          HTTP POST /olimpo/telemetry
          (Auth: X-Olimpo-Key)
                ↓
          ProcessTelemetryJob → queue
                ↓
          TelemetryStore → DB
                ↓
          broadcast → Soketi (Olimpo dashboard)
```

---

## Modelo de datos — TelemetryEvent

Cada evento tiene la siguiente estructura JSON:

```json
{
  "app":        "documentia",
  "session_id": "abc123",
  "type":       "query | log | exception | datatable | event | request | websocket",
  "timestamp":  "2026-05-18T12:00:00.000Z",
  "duration_ms": 12.4,
  "payload":    { ... },
  "context": {
    "tenant":   "acme",
    "user_id":  "uuid",
    "route":    "GET /api/backoffice/users",
    "env":      "production",
    "source":   "ssr | client | cli"
  }
}
```

**`source`** indica el origen de cada request:
- `ssr` — Nuxt server-side rendering hizo el fetch (header `X-Innertia-Source: ssr`)
- `client` — el browser hizo el request directamente (header `X-Innertia-Source: client`)
- `cli` — llamada desde artisan, queue o sin contexto HTTP

El header `X-Innertia-Source` lo envía `useApi.js` del paquete `nuxt-app` automáticamente usando `import.meta.server`. Permite en Olimpo filtrar "queries disparadas por SSR vs cliente" — útil para detectar waterfalls de hidratación.

**Tipos de eventos capturados (primera versión):**

| type | Fuente | Payload clave |
|---|---|---|
| `query` | `DB::listen` | sql, bindings, duration_ms, connection |
| `log` | Canal Log de Laravel | level, message, context |
| `exception` | Exception handler | class, message, file, line, trace |
| `datatable` | DataTable::build() | endpoint, filters, rows, duration_ms |
| `event` | `Event::listen('*')` | event_class, payload |
| `request` | middleware after | method, path, status, duration_ms |

---

## Componentes — Sender

### `TelemetryCollector`

- Singleton por request (bound en el service container)
- Métodos: `record(TelemetryEvent $event): void`
- Al recibir cada evento:
  1. Lo agrega al batch en memoria (`$this->batch[]`)
  2. Lo emite inmediatamente por WebSocket: `broadcast(new DevtoolsEvent($event))->toPrivate("innertia.devtools.{$sessionId}")`
- El `sessionId` se extrae del claim `jti` del JWT via `auth()->payload()->get('jti')` (fallback a UUID para requests sin auth / CLI)
- El frontend obtiene el `jti` decodificando el JWT que ya tiene almacenado: `JSON.parse(atob(token.split('.')[1])).jti` — sin llamadas extra

### `TelemetryExporter`

- Se invoca en `terminate()` del middleware (después de enviar el response)
- Toma el batch del `TelemetryCollector`
- Si el batch está vacío, no hace nada
- HTTP POST a `{OLIMPO_URL}/olimpo/telemetry` con header `X-Olimpo-Key`
- Fire-and-forget: errores se logean localmente pero no propagan
- Timeout: 3 segundos máximo para no colgar el worker

### `TelemetryMiddleware`

- Registrado automáticamente en `TelemetryServiceProvider`
- `handle()`: inicializa el `TelemetryCollector`, registra los listeners
- `terminate()`: llama a `TelemetryExporter::flush()`

### `TelemetryServiceProvider`

- Activa el módulo solo si `TELEMETRY_ENABLED=true` Y el rol del usuario tiene el permiso `devtools.send` (o si es `local`/`development`)
- Registra: `DB::listen`, `Log::listen`, `Event::listen('*')`, handler de exceptions
- Bind del `TelemetryCollector` como singleton

---

## Componentes — Receiver (Olimpo)

### `POST /olimpo/telemetry`

- Protegido con `olimpo.auth` middleware (ya existe, usa `X-Olimpo-Key`)
- Valida estructura básica del batch
- Despacha `ProcessTelemetryJob::dispatch($batch)` — responde 202 inmediatamente
- No bloquea

### `ProcessTelemetryJob`

- Queue: `telemetry` (cola dedicada para no mezclar con jobs de negocio)
- Almacena cada evento en la tabla `telemetry_events`
- Después de almacenar: `broadcast(new TelemetryBatchReceived($batch))` → WebSocket del dashboard Olimpo

### Migración: `telemetry_events`

```php
Schema::create('telemetry_events', function (Blueprint $table) {
    $table->id();
    $table->string('app');
    $table->string('session_id')->index();
    $table->string('type')->index();
    $table->timestamp('occurred_at')->index();
    $table->unsignedInteger('duration_ms')->nullable();
    $table->json('payload');
    $table->json('context');
    $table->timestamps();

    $table->index(['app', 'type']);
    $table->index(['app', 'occurred_at']);
});
```

### Retención

- Purga automática de eventos con más de 7 días (comando artisan `telemetry:prune`, scheduleable)
- En local/development: retención de 1 día

---

## Configuración

```php
// config/telemetry.php (publicable)
return [
    'enabled'     => env('TELEMETRY_ENABLED', false),
    'app_name'    => env('APP_NAME', 'app'),
    'olimpo_url'  => env('OLIMPO_URL'),
    'olimpo_key'  => env('OLIMPO_KEY'),
    'queue'       => env('TELEMETRY_QUEUE', 'telemetry'),
    'timeout'     => 3, // segundos, HTTP export
    'retention_days' => env('TELEMETRY_RETENTION_DAYS', 7),

    // Qué capturar (se puede desactivar individualmente)
    'capture' => [
        'queries'     => true,
        'logs'        => true,
        'exceptions'  => true,
        'datatables'  => true,
        'events'      => true,
        'requests'    => true,
    ],

    // Excepciones que NO se reportan (igual que olimpo.except)
    'except' => [
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Auth\AuthenticationException::class,
    ],
];
```

---

## Control de activación

El módulo se activa por capas:

1. `TELEMETRY_ENABLED=true` en `.env` (primer candado)
2. El usuario autenticado tiene el permiso `devtools` en sus roles (segundo candado)
3. En entornos `local` y `development`: se activa automáticamente sin chequeo de permisos

Esto permite activarlo puntualmente en producción para un usuario de soporte sin afectar a nadie más.

---

## Estructura de archivos

```
src/Telemetry/
├── TelemetryServiceProvider.php
├── TelemetryCollector.php
├── TelemetryExporter.php
├── Events/
│   ├── DevtoolsEvent.php          ← broadcast a nuxt-devtools (sender)
│   └── TelemetryBatchReceived.php ← broadcast a Olimpo dashboard (receiver)
├── Http/
│   ├── Controllers/
│   │   └── TelemetryController.php
│   └── Middleware/
│       └── TelemetryMiddleware.php
├── Jobs/
│   └── ProcessTelemetryJob.php
├── Models/
│   └── TelemetryEvent.php
├── Collectors/
│   ├── QueryCollector.php
│   ├── LogCollector.php
│   ├── ExceptionCollector.php
│   ├── DataTableCollector.php
│   ├── EventCollector.php
│   └── RequestCollector.php
└── Console/
    └── PruneTelemetryCommand.php
```

---

## Fuera de alcance (esta iteración)

- UI en Olimpo (pilar 2 frontend)
- `innertia-nuxt-devtools` overlay
- Agregaciones / métricas (promedios, percentiles)
- Alertas o umbrales
- Integración con Telescope

---

## Próximo paso

Invocar `writing-plans` para crear el plan de implementación paso a paso.
