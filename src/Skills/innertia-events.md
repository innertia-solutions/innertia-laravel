---
name: innertia-events
description: Use when working with DomainEvents, EventBus, Triggers, multi-channel notifications (realtime/webhook/mail/web). Trigger for "emitir evento", "DomainEvent", "EventBus", "DomainEventKey", "Trigger", "Innertia::events()", "broadcast", "suscribir a eventos".
---

# Innertia — Event Bus + DomainEvents

Sistema tipado de eventos sobre Laravel. Eventos declaran su key via **enum** (DomainEventKey), suscribirse usa `Innertia::events()->listen(...)` con autocompletado, y el bus tiene catálogo introspectable + test fakes.

Por debajo usa Laravel Event Dispatcher (queue, broadcast, model events siguen funcionando), pero la API del consumer es 100% Innertia.

## Conceptos

| Pieza | Qué hace |
|---|---|
| `DomainEventKey` (interfaz) | La implementan enums que catalogan eventos. Una case = un evento. |
| `DomainEvent` (abstract) | Base class de cada evento. Cada concreto declara `key()` retornando un case del enum. |
| `EventBus` (singleton) | Listener registry tipado, dispatch con aislamiento de excepciones. |
| `Trigger` (interfaz) | Patrón de clase con `on(): DomainEventKey` + `handle($event)`. |
| `EventBusFake` | Test helper con assertions tipo `assertDispatched(...)`. |

## Definir un evento

1. Crear el enum del catálogo:

```php
namespace App\Domains\Orders\Events;

use Innertia\Platform\Events\DomainEventKey;

enum OrderEvent: string implements DomainEventKey
{
    case Placed   = 'order.placed';
    case Shipped  = 'order.shipped';
    case Cancelled = 'order.cancelled';

    public function key(): string
    {
        return $this->value;
    }
}
```

Convención de valores: `<feature>.<verb>` en snake.case.

2. Crear el evento extendiendo `DomainEvent`:

```php
namespace App\Domains\Orders\Events;

use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;
use App\Domains\Orders\Models\Order;

class OrderShipped extends DomainEvent
{
    public function __construct(public readonly Order $order) {}

    public function key(): DomainEventKey
    {
        return OrderEvent::Shipped;
    }

    // Optional: per-instance variant for granular subscriptions
    public function variant(): ?string
    {
        return null;  // or e.g. $this->order->region
    }

    // Optional: multi-channel routing (realtime/webhook/mail/web)
    public function channels(): array
    {
        return ['realtime', 'webhook', 'mail'];
    }

    public function payload(): array
    {
        return ['order_id' => $this->order->id];
    }
}
```

3. Disparar (igual que cualquier Laravel event):

```php
event(new OrderShipped($order));
```

## Suscribirse — listeners y triggers

### Listener closure (anonimo, registrado en boot)

```php
// AppServiceProvider::boot()
Innertia::events()->listen(OrderEvent::Shipped, function ($event) {
    Log::info("Order shipped: {$event->order->id}");
});
```

### Listener clase con `handle()`

```php
class SendShipmentEmail
{
    public function handle(OrderShipped $event): void
    {
        Mail::to($event->order->customer)->send(new ShipmentMail($event->order));
    }
}

// Boot:
Innertia::events()->listen(OrderEvent::Shipped, SendShipmentEmail::class);
```

### Trigger (clase con contrato `Trigger`)

```php
namespace App\Triggers;

use Innertia\Platform\Events\Trigger;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Events\DomainEventKey;
use App\Domains\Orders\Events\OrderEvent;

class AuditOrderShipped implements Trigger
{
    public static function on(): DomainEventKey
    {
        return OrderEvent::Shipped;
    }

    public function handle(DomainEvent $event): void
    {
        AuditLog::record('order.shipped', $event->payload());
    }
}

// Boot:
Innertia::events()->trigger(AuditOrderShipped::class);
```

### Listener condicional (`when`)

```php
Innertia::events()->when(
    OrderEvent::Shipped,
    fn ($event) => $event->order->amount > 1000,
    NotifyVipCustomer::class,
);
```

### Bulk registration

```php
Innertia::events()->listenMany([
    OrderEvent::Placed->key()    => RecordSale::class,
    OrderEvent::Shipped->key()   => UpdateInventory::class,
    OrderEvent::Cancelled->key() => ReleaseInventory::class,
]);
```

## Async (encolar listener)

Listener corre **sync por default**. Para encolar, implementar `Illuminate\Contracts\Queue\ShouldQueue`:

```php
use Illuminate\Contracts\Queue\ShouldQueue;

class SendShipmentEmail implements ShouldQueue
{
    public function handle(OrderShipped $event): void { /* ... */ }
}
```

El bus detecta `ShouldQueue` y dispatchea via Laravel queue automáticamente. Closures NO se encolan (no son serializables).

## Aislamiento de excepciones

Si un listener tira excepción:
- Se captura
- Se loguea via `Log::error` con contexto: event class, key, listener, message, trace
- **El dispatch continúa con los listeners restantes**

Esto evita que un listener defectuoso reviente toda la cadena de side-effects.

## Catalog — introspección

Registrar enums permite descubrirlos vía `Innertia::events()->catalog()`:

```php
// Boot (típicamente desde un feature service provider)
Innertia::events()->registerCatalog(OrderEvent::class);

// Cualquier momento:
$catalog = Innertia::events()->catalog();
// [
//   'OrderEvent' => [
//     'enum'      => 'App\Domains\Orders\Events\OrderEvent',
//     'cases'     => ['Placed', 'Shipped', 'Cancelled'],
//     'listeners' => [
//       'order.placed'    => 1,
//       'order.shipped'   => 3,
//       'order.cancelled' => 0,
//     ],
//   ],
// ]
```

Útil para devtools, frontend de suscripciones, o auditoría de qué está activo.

## Test helpers

```php
use Innertia\Platform\Events\EventBusFake;

it('fires OrderShipped when shipment is recorded', function () {
    $fake = EventBusFake::fake();  // reemplaza el bus en el container

    // ... ejercitar código ...

    $fake->assertDispatched(OrderEvent::Shipped);
    $fake->assertDispatchedTimes(OrderEvent::Shipped, 1);
    $fake->assertDispatched(OrderEvent::Shipped, fn ($e) => $e->order->id === '...');
    $fake->assertNotDispatched(OrderEvent::Cancelled);
    $fake->assertNothingDispatched();
});
```

Mientras esté activo el fake, listeners reales **no corren** — solo se registra el dispatch.

## Channels multi-delivery (mantenido del sistema anterior)

`channels()` controla cómo se entrega el evento más allá de los listeners Innertia:

| Channel | Qué hace |
|---|---|
| `'realtime'` | Broadcast via Pusher/Reverb. Frontend recibe vía `useRealtime()`. |
| `'webhook'` | Dispatch a endpoints registrados en tabla `webhooks` con HMAC signing. |
| `'mail'` | Envia `toMail()` mailable a suscriptores del `subscribable()`. |
| `'web'` | Crea notificación in-app con shape de `toWeb()`. |

`DomainEventRouter` (listener interno) procesa los channels — vos no lo tocás.

## Migración desde la API anterior

Si tu proyecto tenía `class MyEvent extends DomainEvent { const KEY = 'my.event'; }` o `webhookKey()` override:

| Antes | Ahora |
|---|---|
| `const KEY = 'my.event';` | Crear enum `MyEvent` con case `MyEvent` + `public function key()` |
| `public function webhookKey(): string { return 'my.event'; }` | `public function key(): DomainEventKey { return MyFeatureEvent::My; }` |
| `public function resolvedKey()` override custom | `public function variant(): ?string { return $this->dynamicPart; }` — el bus combina key + variant |
| `Event::listen('my.event', ...)` | `Innertia::events()->listen(MyFeatureEvent::My, ...)` |

El listener Laravel-style sigue funcionando (back-compat), pero la API tipada es la recomendada.

## Performance

- Listener registration es O(1) por entrada.
- Dispatch es O(N) donde N = número de listeners para esa key.
- `catalog()` solo recorre los enums registrados — costo despreciable.
- Closures sync no tocan queue/storage.
- ShouldQueue listeners encolan via Laravel queue normal.
