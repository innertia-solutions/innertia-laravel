<?php

namespace Innertia\Notifications\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Innertia\Notifications\Models\Notification;

/**
 * Se emite al crear una notificación web. Entrega en vivo al dueño vía el
 * canal privado `user.{id}` (el frontend escucha con useUserRealtime).
 */
class NotificationSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly Notification $notification) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.' . $this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'NotificationSent';
    }

    public function broadcastWith(): array
    {
        return [
            'id'         => $this->notification->id,
            'user_id'    => $this->notification->user_id,
            'type'       => $this->notification->type,
            'key'        => $this->notification->key,
            'title'      => $this->notification->title,
            'body'       => $this->notification->body,
            'data'       => $this->notification->data,
            'read_at'    => $this->notification->read_at,
            'created_at' => $this->notification->created_at,
        ];
    }
}
