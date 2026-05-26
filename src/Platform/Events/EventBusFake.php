<?php

namespace Innertia\Platform\Events;

use PHPUnit\Framework\Assert as PHPUnit;

class EventBusFake extends EventBus
{
    /** @var array<int, DomainEvent> */
    protected array $dispatched = [];

    public static function fake(): self
    {
        $fake = new self();
        app()->instance(EventBus::class, $fake);
        return $fake;
    }

    public function dispatch(DomainEvent $event): void
    {
        // Record but do NOT invoke listeners. Tests assert against dispatched array.
        $this->dispatched[] = $event;
    }

    // ── Assertions ───────────────────────────────────────────────────────────

    public function assertDispatched(DomainEventKey $key, ?callable $callback = null): void
    {
        $matches = $this->dispatchedMatching($key, $callback);

        PHPUnit::assertNotEmpty(
            $matches,
            "Expected event with key [{$key->key()}] to be dispatched but it was not."
        );
    }

    public function assertDispatchedTimes(DomainEventKey $key, int $times): void
    {
        $count = count($this->dispatchedMatching($key));

        PHPUnit::assertSame(
            $times,
            $count,
            "Expected event [{$key->key()}] to be dispatched {$times} times, got {$count}."
        );
    }

    public function assertNotDispatched(DomainEventKey $key, ?callable $callback = null): void
    {
        $matches = $this->dispatchedMatching($key, $callback);

        PHPUnit::assertEmpty(
            $matches,
            "Expected event [{$key->key()}] not to be dispatched but it was."
        );
    }

    public function assertNothingDispatched(): void
    {
        PHPUnit::assertEmpty(
            $this->dispatched,
            'Expected no events dispatched, got ' . count($this->dispatched) . '.'
        );
    }

    /** @return array<int, DomainEvent> */
    public function dispatchedMatching(DomainEventKey $key, ?callable $callback = null): array
    {
        return array_values(array_filter(
            $this->dispatched,
            function (DomainEvent $event) use ($key, $callback) {
                if ($event->key()->key() !== $key->key()) {
                    return false;
                }
                return $callback === null || $callback($event);
            }
        ));
    }

    /** @return array<int, DomainEvent> */
    public function all(): array
    {
        return $this->dispatched;
    }
}
