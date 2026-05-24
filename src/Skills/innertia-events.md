---
name: innertia-events
description: Use when working with DomainEvents, realtime broadcasting, subscriptions, multi-channel notifications (realtime / webhook / mail / web). Trigger for "emitir evento", "DomainEvent", "broadcast", "subscriptores", "Subscribable", "channels", "DomainEventRouter".
---

# Innertia — DomainEvents + Realtime + Notificaciones

Sistema de eventos multi-canal. Un `DomainEvent` se dispara una vez y el `DomainEventRouter` lo distribuye automáticamente a los canales que el evento declara: **realtime**, **webhook**, **mail**, **web** (notificaciones en-app).

## DomainEvent base

```php
// src/Platform/Events/DomainEvent.php
abstract class DomainEvent implements ShouldBroadcast { /* ... */ }
```

Métodos a override en subclases:

| Método | Cuándo | Default |
|---|---|---|
| `channels(): array` | Qué canales activar para este evento | `[]` (ninguno) |
| `payload(): array` | Datos públicos del evento | props públicas del constructor |
| `webhookKey(): string` | Clave para matching de webhooks | `Str::kebab(class_basename($this))` |
| `subscribable(): ?Model` | Modelo "dueño" de subscriptores | `null` |
| `toMail(): ?InnertiaMailable` | Mailable a enviar a subscriptores | `null` |
| `toWeb(): ?array` | Shape de la notificación in-app | `null` |
| `ancestors(): array` | Modelos padre que también reciben el evento | `[]` |
| `broadcastOn(): Channel` | Canal de broadcast (realtime) | `PrivateChannel("tenant.{tenantId}")` |
| `broadcastAs(): string` | Nombre del evento para el cliente | `webhookKey()` |

## Definir un evento

```php
namespace App\Domains\Orders\Events;

use App\Domains\Orders\Models\Order;
use Innertia\Platform\Events\DomainEvent;

class OrderShipped extends DomainEvent {
    public function __construct(public readonly Order $order) {}

    public function channels(): array {
        return ['realtime', 'webhook', 'mail'];
    }

    public function webhookKey(): string {
        return 'order.shipped';
    }

    public function payload(): array {
        return [
            'order_id'  => $this->order->id,
            'number'    => $this->order->number,
            'shipped_at' => $this->order->shipped_at?->toIso8601String(),
        ];
    }

    public function subscribable(): ?Model {
        return $this->order;
    }

    public function toMail(): ?InnertiaMailable {
        return new OrderShippedMail($this->order);
    }
}
```

## Disparar el evento

```php
// Estándar Laravel
event(new OrderShipped($order));

// Fluent helper (heredado de Dispatchable)
OrderShipped::dispatch($order);

// Override de canales runtime (útil en jobs/tests)
OrderShipped::dispatch($order, channels: ['webhook']);
```

El `DomainEventRouter` lo recibe y, según `channels()`:

- **`realtime`** → broadcast via Laravel Broadcasting (Pusher / Reverb / Soketi)
- **`webhook`** → `WebhookService::dispatchForEvent($event)` notifica a webhooks registrados con matching key
- **`mail`** → envía `toMail()` a los `subscribable()->subscribersByChannel('order.shipped')['mail']`
- **`web`** → crea registros en `user_notifications` para `subscribable()->subscribersByChannel(...)['web']`

## Realtime / Broadcasting

Usa Laravel Broadcasting estándar. El paquete NO bundlea ningún driver — configurá tu `BROADCAST_DRIVER` en `.env` (`pusher`, `reverb`, `ably`).

```env
BROADCAST_DRIVER=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
```

En el frontend (no parte del paquete), suscribite al canal:

```js
Echo.private(`tenant.${tenantId}`)
    .listen('.order.shipped', (payload) => { /* ... */ });
```

(El `.` inicial del event name es porque `broadcastAs()` se serializa como custom event name.)

## Subscriptores

Cuando un evento tiene `subscribable()` y `channels` incluye `mail` o `web`, el router consulta los suscriptores del modelo via el trait `Subscribable`.

### Trait Subscribable en modelos

```php
use Innertia\Platform\Traits\Subscribable;

class Order extends Model {
    use Subscribable;
}

// Suscribir un user a todos los eventos del modelo
$order->subscribe($user, events: ['*'], channels: ['mail', 'web']);

// Suscribir solo a eventos específicos
$order->subscribe($user, events: ['order.shipped', 'order.delivered'], channels: ['mail']);

// Wildcards
$order->subscribe($user, events: ['order.*'], channels: ['web']);

// Quitar suscripción
$order->unsubscribe($user);

// Listar suscriptores (por evento o todos)
$order->subscribers();
$order->subscribers('order.shipped');
$order->subscribersByChannel('order.shipped');  // ['mail' => Collection, 'web' => Collection]
```

### Modelo Subscription

```
subscriptions (id, tenant_id, subscribable_type, subscribable_id, user_id, events jsonb, channels jsonb, created_at)
```

Método `Subscription::matchesEvent($eventKey)` evalúa wildcards:

- `*` → match all
- `orders.*` → match cualquier evento que empiece con `orders.`
- `orders.shipped` → match exacto

## Notificaciones in-app (canal `web`)

Cuando `channels()` incluye `web` y el evento define `toWeb()`:

```php
public function toWeb(): ?array {
    return [
        'title'     => 'Tu pedido fue enviado',
        'body'      => "Pedido #{$this->order->number}",
        'url'       => "/orders/{$this->order->id}",
        'icon'      => 'truck',
        'severity'  => 'info',
    ];
}
```

El router crea un registro por cada suscriptor en `user_notifications`. El usuario las consulta en `/notifications` (endpoint del paquete).

## Patrones recomendados

- **Un evento por hito de negocio**, no por cambio técnico. `OrderShipped`, no `OrderUpdated`.
- **`channels()` declarativo**, no condicional con if. Si necesitás condicionalidad runtime, pasá `channels:` al `dispatch()`.
- **`payload()` solo con campos públicos**. Para datos sensibles, los suscriptores pueden re-consultar via API.
- **`subscribable()` apunta al modelo que define el grupo de listeners** (la Order, no el User).
- **Cuando el evento debe sobrevivir compaction/queue restart**, recordá que los DomainEvents son serializables (`SerializesModels`).

## Skills relacionados

- `innertia-webhooks` — qué pasa cuando `channels` incluye `webhook`
- `innertia-mail` — qué pasa cuando `channels` incluye `mail`
- `innertia-framework` — DomainEvent vs UseCase, cuándo cada uno
