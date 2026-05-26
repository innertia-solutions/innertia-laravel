<?php

namespace Innertia\Platform\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Innertia typed event bus.
 *
 * Sits on top of Laravel's event dispatcher: a single Laravel listener
 * (registered by InnertiaServiceProvider) forwards every DomainEvent here.
 * This class then distributes to enum-keyed listeners and Triggers
 * registered via Innertia::events()->...
 *
 * Listeners run sync in registration order. Exceptions are caught and
 * logged — other listeners continue. To run async, the listener class
 * must implement Illuminate\Contracts\Queue\ShouldQueue.
 */
class EventBus
{
    /** @var array<string, array<int, array{handler: callable|string, predicate: ?callable}>> */
    protected array $listeners = [];

    /** @var array<string, true> registered enum FQNs (for catalog) */
    protected array $catalogEnums = [];

    // ── Subscription ─────────────────────────────────────────────────────────

    public function listen(DomainEventKey $key, callable|string $handler): static
    {
        $this->listeners[$key->key()][] = ['handler' => $handler, 'predicate' => null];
        return $this;
    }

    public function when(DomainEventKey $key, callable $predicate, callable|string $handler): static
    {
        $this->listeners[$key->key()][] = ['handler' => $handler, 'predicate' => $predicate];
        return $this;
    }

    public function trigger(string $triggerClass): static
    {
        if (! is_subclass_of($triggerClass, Trigger::class)) {
            throw new \InvalidArgumentException(
                "Class {$triggerClass} must implement " . Trigger::class
            );
        }

        $key = $triggerClass::on();
        $this->listeners[$key->key()][] = ['handler' => $triggerClass, 'predicate' => null];
        return $this;
    }

    /**
     * @param array<string, callable|string> $map  key-string → handler
     */
    public function listenMany(array $map): static
    {
        foreach ($map as $keyString => $handler) {
            $this->listeners[$keyString][] = ['handler' => $handler, 'predicate' => null];
        }
        return $this;
    }

    public function forget(DomainEventKey $key, callable|string|null $handler = null): static
    {
        $k = $key->key();
        if ($handler === null) {
            unset($this->listeners[$k]);
        } else {
            $this->listeners[$k] = array_values(array_filter(
                $this->listeners[$k] ?? [],
                fn ($entry) => $entry['handler'] !== $handler
            ));
        }
        return $this;
    }

    // ── Catalog ──────────────────────────────────────────────────────────────

    public function registerCatalog(string $enumClass): static
    {
        if (! is_subclass_of($enumClass, DomainEventKey::class)) {
            throw new \InvalidArgumentException(
                "Enum {$enumClass} must implement " . DomainEventKey::class
            );
        }
        $this->catalogEnums[$enumClass] = true;
        return $this;
    }

    /** @return array<string, array{enum: string, cases: array<string>, listeners: array<string, int>}> */
    public function catalog(): array
    {
        $out = [];
        foreach (array_keys($this->catalogEnums) as $enumClass) {
            $shortName = class_basename($enumClass);
            $cases = array_map(fn ($c) => $c->name, $enumClass::cases());
            $listeners = [];
            foreach ($enumClass::cases() as $case) {
                $listeners[$case->key()] = count($this->listeners[$case->key()] ?? []);
            }
            $out[$shortName] = [
                'enum'      => $enumClass,
                'cases'     => $cases,
                'listeners' => $listeners,
            ];
        }
        return $out;
    }

    // ── Dispatch ─────────────────────────────────────────────────────────────

    public function dispatch(DomainEvent $event): void
    {
        $base     = $event->key()->key();
        $resolved = $event->resolvedKey();

        // Listeners for the resolved key (with variant), then base key.
        $keys = $base === $resolved ? [$base] : [$resolved, $base];

        foreach ($keys as $k) {
            foreach ($this->listeners[$k] ?? [] as $entry) {
                if ($entry['predicate'] !== null && ! ($entry['predicate'])($event)) {
                    continue;
                }

                try {
                    $this->invoke($entry['handler'], $event);
                } catch (\Throwable $e) {
                    Log::error('EventBus listener failed', [
                        'event'    => get_class($event),
                        'key'      => $k,
                        'listener' => $this->describeListener($entry['handler']),
                        'error'    => $e->getMessage(),
                        'trace'    => $e->getTraceAsString(),
                    ]);
                    // Continue with the next listener — isolation.
                }
            }
        }
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    protected function invoke(callable|string $handler, DomainEvent $event): void
    {
        if (is_string($handler)) {
            $instance = app($handler);

            if ($instance instanceof ShouldQueue) {
                dispatch(function () use ($handler, $event) {
                    app($handler)->handle($event);
                })->onQueue(method_exists($instance, 'viaQueue') ? $instance->viaQueue() : null);
                return;
            }

            // Triggers and listener classes both use handle()
            $method = method_exists($instance, 'handle') ? 'handle' : '__invoke';
            $instance->{$method}($event);
            return;
        }

        // Callable / closure — always sync
        $handler($event);
    }

    protected function describeListener(callable|string $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }
        if ($handler instanceof \Closure) {
            return 'Closure';
        }
        if (is_array($handler) && count($handler) === 2) {
            $cls = is_object($handler[0]) ? get_class($handler[0]) : $handler[0];
            return "{$cls}::{$handler[1]}";
        }
        return 'callable';
    }
}
