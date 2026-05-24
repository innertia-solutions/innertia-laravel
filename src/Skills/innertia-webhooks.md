---
name: innertia-webhooks
description: Use when working with outbound webhooks — registering Webhook models, dispatching webhook deliveries, HMAC signing, retry/backoff, WebhookLog. Trigger for "configurar webhook", "Webhook::create", "X-Innertia-Signature", "webhook secret", "dispatchForEvent", "DispatchWebhookJob".
---

# Innertia — Webhooks

Sistema de webhooks **salientes**: tu app envía notificaciones HTTP a URLs registradas cuando ocurren `DomainEvent`s con `channels()` que incluye `'webhook'`.

**El paquete NO expone una REST API para administrar webhooks** — solo provee el modelo, el service y el job. Cada producto implementa su propio CRUD si necesita UI de gestión.

## Componentes

| Archivo | Rol |
|---|---|
| `src/Webhooks/Models/Webhook.php` | Modelo: URL destino, secret, eventos suscritos, flag active |
| `src/Webhooks/Models/WebhookLog.php` | Auditoría de cada delivery (request + response + status) |
| `src/Webhooks/WebhookService.php` | `dispatchForEvent(DomainEvent)` — orquesta el envío |
| `src/Webhooks/Jobs/DispatchWebhookJob.php` | Job en queue con retry/backoff |

## Schema mínimo

```
webhooks (id, tenant_id, url, description, events jsonb, secret, active bool, created_at, updated_at)
webhook_logs (id, webhook_id, event_key, request_body jsonb, response_status, response_body, attempt, delivered_at)
```

`events` es un array de keys con soporte de wildcards: `['order.*', 'invoice.paid']`.

## Registrar un webhook

Como no hay REST API, se hace via tinker, seeder o tu propio controller:

```php
use Innertia\Webhooks\Models\Webhook;

$webhook = Webhook::create([
    'tenant_id'   => Innertia::tenant()->getKey(),
    'url'         => 'https://customer-app.example.com/webhooks/innertia',
    'description' => 'Customer integration — order events',
    'events'      => ['order.*', 'invoice.paid'],
    'secret'      => bin2hex(random_bytes(32)),   // 64-char hex
    'active'      => true,
]);
```

## Disparo automático desde eventos

Cuando un `DomainEvent` declara `channels()` con `'webhook'`, el `DomainEventRouter` invoca:

```php
$webhookService->dispatchForEvent($event);
```

Que internamente:

1. Resuelve la `webhookKey()` del evento (ej. `order.shipped`)
2. Busca `Webhook::where('active', true)` cuyo `events` matchee la key (soporta wildcards)
3. Por cada match, dispatch `DispatchWebhookJob::dispatch($webhook, $eventKey, $payload)` a la queue `webhooks`
4. El job hace el POST HTTP, registra en `webhook_logs`, retry si falla

Ver `innertia-events` para el evento.

## Payload enviado

El body es JSON:

```json
{
  "_event": "order.shipped",
  "_timestamp": "2026-05-24T19:45:00Z",
  "order_id": "uuid-...",
  "number": "ORD-001",
  "shipped_at": "2026-05-24T19:30:00Z"
}
```

(El payload del evento + `_event` + `_timestamp` inyectados por el service.)

## Headers HTTP

```
Content-Type: application/json
X-Innertia-Event: order.shipped
X-Innertia-Delivery: {webhook_log_id}
X-Innertia-Signature: sha256={hmac}
User-Agent: Innertia-Webhooks/1.0
```

`X-Innertia-Signature` se calcula como:

```php
'sha256=' . hash_hmac('sha256', $rawBody, $webhook->secret)
```

## Verificación del lado del receptor

El cliente debe:

```php
// receiver app
$signature = $request->header('X-Innertia-Signature');
$expected  = 'sha256=' . hash_hmac('sha256', $request->getContent(), $webhookSecret);

if (! hash_equals($expected, $signature)) {
    abort(401, 'Invalid signature');
}
```

Usar `hash_equals` para timing-safe compare.

## Retry y backoff

```php
// DispatchWebhookJob defaults
public int $tries   = 3;
public int $timeout = 30;
public array $backoff = [60, 300, 1800];   // 1m, 5m, 30m
public string $queue = 'webhooks';
```

Si los 3 intentos fallan, el log queda registrado con `delivered_at = null` y `response_status` del último intento (o `null` si timeout/error de red).

## Logs

```php
$webhook->logs;                          // HasMany
$webhook->logs()->latest()->limit(50);   // últimas 50 entregas

WebhookLog::where('response_status', '>=', 400)
    ->where('created_at', '>', now()->subDay())
    ->get();   // entregas fallidas en las últimas 24h
```

## Patrón: UI de webhooks en tu producto

El paquete no trae UI, pero el patrón típico:

```php
// app/Apps/Backoffice/Webhooks/WebhooksController.php
public function index(Request $r) {
    return DataTable::create('webhooks')
        ->columns(['id', 'url', 'description', 'events', 'active', 'created_at'])
        ->render(Webhook::query(), $r);
}

public function store(Request $r) {
    $data = $r->validate([
        'url'         => 'required|url',
        'description' => 'nullable|string',
        'events'      => 'required|array|min:1',
        'events.*'    => 'string',
    ]);

    return Webhook::create([
        'tenant_id'   => Innertia::tenant()->getKey(),
        'url'         => $data['url'],
        'description' => $data['description'] ?? null,
        'events'      => $data['events'],
        'secret'      => bin2hex(random_bytes(32)),
        'active'      => true,
    ]);
}

public function rotateSecret(string $id) {
    $w = Webhook::findOrFail($id);
    $w->secret = bin2hex(random_bytes(32));
    $w->save();
    return ['secret' => $w->secret];   // se muestra UNA vez
}
```

## Disparar webhook manualmente (no via evento)

Para casos puntuales sin un `DomainEvent`:

```php
use Innertia\Webhooks\Jobs\DispatchWebhookJob;

DispatchWebhookJob::dispatch(
    $webhook,
    'custom.event',
    ['some' => 'data'],
)->onQueue('webhooks');
```

## Anti-patrones

- ❌ Asumir que el receptor está siempre disponible — usar el sistema de retry, no llamar HTTP sync.
- ❌ Enviar datos sensibles en el payload — el cliente puede re-consultar via API autenticada.
- ❌ Hardcodear webhooks en seeders de prod — manejarlos por tenant via UI/API.
- ❌ Reutilizar secrets entre webhooks — uno por destino. Rotación periódica es buena práctica.

## Skills relacionados

- `innertia-events` — DomainEvents y cómo declarar `channels()` incluyendo webhook
- `innertia-framework` — patrón general de eventos en el paquete
