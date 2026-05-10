<?php

namespace Innertia\Platform\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for real-time events. Override channel(), broadcastAs(), or
 * broadcastWith() to customise; the event auto-broadcasts on dispatch.
 *
 * Usage:
 *   class OrderShipped extends RealtimeEvent
 *   {
 *       public function __construct(public readonly Order $order) {}
 *
 *       public function channel(): Channel
 *       {
 *           return new PrivateChannel('tenant.' . tenant('id'));
 *       }
 *
 *       public function broadcastWith(): array
 *       {
 *           return ['order_id' => $this->order->id, 'status' => $this->order->status];
 *       }
 *   }
 *
 *   OrderShipped::dispatch($order);   // broadcasts automatically
 */
abstract class RealtimeEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The channel(s) to broadcast on.
     * Override to return a Channel, PrivateChannel, PresenceChannel, or array.
     */
    public function broadcastOn(): Channel|array
    {
        return $this->channel();
    }

    /**
     * Default channel: private channel named after the snake_case event class.
     * Override in subclasses for custom routing.
     */
    public function channel(): Channel
    {
        return new PrivateChannel($this->defaultChannelName());
    }

    /**
     * Event name sent to the client. Defaults to the short class name.
     * Override to customise: return 'order.shipped';
     */
    public function broadcastAs(): string
    {
        return class_basename(static::class);
    }

    /**
     * Payload sent to the client.
     * Override to control exactly what data is broadcast.
     */
    public function broadcastWith(): array
    {
        return $this->defaultPayload();
    }

    /**
     * Derives a default channel name from the class name.
     * OrderShipped → 'order-shipped'
     */
    protected function defaultChannelName(): string
    {
        return \Illuminate\Support\Str::kebab(class_basename(static::class));
    }

    /**
     * Reflects all public properties as the broadcast payload.
     */
    protected function defaultPayload(): array
    {
        $payload = [];

        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $payload[$prop->getName()] = $prop->getValue($this);
        }

        return $payload;
    }
}
