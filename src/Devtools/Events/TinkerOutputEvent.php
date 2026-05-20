<?php

namespace Innertia\Devtools\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class TinkerOutputEvent implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        private readonly string $sessionId,
        private readonly array  $result,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        // Channel name (without "private-" prefix — Laravel adds it automatically)
        return new PrivateChannel("innertia.tinker.{$this->sessionId}");
    }

    public function broadcastAs(): string
    {
        return 'tinker.output';
    }

    public function broadcastWith(): array
    {
        return $this->result;
    }
}
