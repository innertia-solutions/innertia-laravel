<?php

namespace Innertia\Platform\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Innertia\Mail\InnertiaMailable;
use Innertia\Mail\NotificationMail;

/**
 * Base class for all domain events. Define which channels the event delivers to.
 *
 * Usage:
 *   class OrderShipped extends DomainEvent
 *   {
 *       public function __construct(public readonly Order $order) {}
 *
 *       public function channels(): array
 *       {
 *           return ['realtime', 'webhook', 'mail'];
 *       }
 *
 *       public function broadcastOn(): Channel
 *       {
 *           return new PrivateChannel('tenant.' . tenant('id'));
 *       }
 *
 *       public function toMail(): InnertiaMailable
 *       {
 *           // Option A — dedicated mailable class
 *           return new OrderShippedMail($this->order);
 *
 *           // Option B — generic fluent builder (no separate class needed)
 *           return NotificationMail::make()
 *               ->withSubject('Tu pedido fue enviado')
 *               ->title('¡Pedido en camino!')
 *               ->line('Tu pedido #' . $this->order->id . ' fue enviado.')
 *               ->table(['Campo', 'Valor'], [['Estado', 'Enviado']])
 *               ->action('Ver pedido', url('/orders/' . $this->order->id))
 *               ->panel('Entrega estimada: 2-3 días hábiles.', 'info');
 *       }
 *
 *       public function subscribable(): Model
 *       {
 *           return $this->order;
 *       }
 *   }
 *
 *   OrderShipped::dispatch($order);
 */
abstract class DomainEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Delivery channels for this event.
     * Supported: 'realtime', 'webhook', 'mail'
     */
    public function channels(): array
    {
        return [];
    }

    // ── Realtime ──────────────────────────────────────────────────────────────

    /**
     * Returns empty array when 'realtime' is not in channels() — no broadcast.
     * Override broadcastOn() to customise the channel.
     */
    final public function broadcastOn(): array
    {
        if (! in_array('realtime', $this->channels(), true)) {
            return [];
        }

        return [$this->channel()];
    }

    public function channel(): Channel
    {
        return new PrivateChannel($this->defaultChannelName());
    }

    public function broadcastAs(): string
    {
        return class_basename(static::class);
    }

    public function broadcastWith(): array
    {
        return $this->payload();
    }

    // ── Webhook ───────────────────────────────────────────────────────────────

    /**
     * Event key used to match registered webhook endpoints.
     * e.g. OrderShipped → 'order.shipped'
     * Override to use a custom key.
     */
    public function webhookKey(): string
    {
        return Str::snake(class_basename(static::class), '.');
    }

    // ── Mail ──────────────────────────────────────────────────────────────────

    /**
     * Return an InnertiaMailable to send when 'mail' is in channels().
     * Recipients: direct delivery goes to subscribable entity's subscribers.
     * Return null to silently skip mail delivery.
     */
    public function toMail(): ?InnertiaMailable
    {
        return null;
    }

    // ── Subscriptions ─────────────────────────────────────────────────────────

    /**
     * Return the model that owns subscriptions for this event.
     * Subscribers will be notified via their preferred channels.
     */
    public function subscribable(): ?Model
    {
        return null;
    }

    // ── Payload ───────────────────────────────────────────────────────────────

    /**
     * Shared payload for realtime broadcast, webhooks, and subscriptions.
     * Override to control exactly what data is sent.
     */
    public function payload(): array
    {
        $payload = [];

        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($this);
            $payload[$prop->getName()] = $value instanceof Model ? $value->toArray() : $value;
        }

        return $payload;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function defaultChannelName(): string
    {
        return Str::kebab(class_basename(static::class));
    }
}
