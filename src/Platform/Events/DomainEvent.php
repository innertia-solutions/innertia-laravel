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
abstract class DomainEvent implements ShouldBroadcast, IsDomainEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The typed event key — every event must declare its enum.
     *
     *   public function key(): DomainEventKey {
     *       return DirectoryEvent::Moved;
     *   }
     */
    abstract public function key(): DomainEventKey;

    /**
     * Optional variant for granularity (e.g. workflow step name).
     * Appended to resolvedKey() if non-null: "{key}.{variant}".
     */
    public function variant(): ?string
    {
        return null;
    }

    /**
     * Runtime channel override set via dispatch($model, channels: [...]).
     * Null means "use the event's own channels() definition".
     */
    public ?array $channelsOverride = null;

    /**
     * Delivery channels for this event.
     * Supported: 'realtime', 'webhook', 'mail'
     */
    public function channels(): array
    {
        return [];
    }

    /**
     * Channels resolved at runtime.
     * Always use this instead of channels() inside the framework internals.
     */
    public function resolveChannels(): array
    {
        return $this->channelsOverride ?? $this->channels();
    }

    /**
     * Dispatch the event with an optional channel override.
     *
     *   OrderShipped::dispatch($order);                        // all channels
     *   OrderShipped::dispatch($order, channels: ['mail']);     // mail only
     *   OrderShipped::dispatch($order, channels: ['webhook']);  // webhook only
     */
    public static function dispatch(mixed ...$arguments): mixed
    {
        $channels = isset($arguments['channels']) ? $arguments['channels'] : null;
        unset($arguments['channels']);

        /** @var static $event */
        $event = new static(...$arguments);

        if ($channels !== null) {
            $event->channelsOverride = $channels;
        }

        return event($event);
    }

    // ── Realtime ──────────────────────────────────────────────────────────────

    /**
     * Returns empty array when 'realtime' is not active — no broadcast.
     * Override channel() to customise the broadcast channel.
     */
    final public function broadcastOn(): array
    {
        if (! in_array('realtime', $this->resolveChannels(), true)) {
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
        return $this->resolvedKey();
    }

    public function broadcastWith(): array
    {
        return $this->payload();
    }

    // ── Webhook ───────────────────────────────────────────────────────────────

    /**
     * Canonical instance key for routing/subscription matching.
     * Derived from key() + variant() — not overrideable.
     */
    final public function resolvedKey(): string
    {
        $base = $this->key()->key();
        $v = $this->variant();
        return $v !== null ? "{$base}.{$v}" : $base;
    }

    /**
     * Modelos padre que también deben recibir notificación de este evento.
     * Los suscriptores de estos modelos son notificados igual que los del subscribable().
     *
     * Ejemplo: un WorkflowTransitioned sobre un Project retorna [$project->program]
     * para que el manager suscrito al Program también sea notificado.
     */
    public function ancestors(): array
    {
        return [];
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

    // ── Web ───────────────────────────────────────────────────────────────────

    /**
     * Return notification data for the web notification center.
     * Called when 'web' is in channels(). Creates a record in user_notifications.
     * Return null to silently skip web notification.
     *
     * Example:
     *   public function toWeb(): array
     *   {
     *       return [
     *           'title' => 'Order shipped',
     *           'body'  => "Your order #{$this->order->id} is on its way.",
     *       ];
     *   }
     */
    public function toWeb(): ?array
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
