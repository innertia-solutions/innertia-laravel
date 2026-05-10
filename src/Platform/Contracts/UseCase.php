<?php

namespace Innertia\Platform\Contracts;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class UseCase implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    public string $queue   = 'use-cases';
    public int    $tries   = 3;
    public int    $timeout = 60;

    abstract public function execute(): mixed;

    /** Called by the queue worker. */
    public function handle(): void
    {
        $this->execute();
    }

    /**
     * Dispatch to a queue.
     *
     *   (new CreateOrder(...))->onQueue();            // → 'use-cases'
     *   (new CreateOrder(...))->onQueue('critical');  // → 'critical'
     */
    public function onQueue(?string $queue = null): void
    {
        $this->queue = $queue ?? 'use-cases';

        dispatch($this);
    }

    /**
     * Dispatch with a delay to the default queue.
     *
     *   (new CreateOrder(...))->delay(now()->addMinutes(5));
     *   (new CreateOrder(...))->delay(30); // seconds
     */
    public function delay(\DateTimeInterface|\DateInterval|int $delay): void
    {
        dispatch($this)->delay($delay);
    }
}
