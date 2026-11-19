<?php

use Illuminate\Support\Facades\Queue;
use Innertia\Facades\Innertia;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Saas\Models\Tenant;

class DelayedProbeUseCase extends UseCase
{
    public static ?string $seenTenant = null;

    public function execute(): void
    {
        self::$seenTenant = Innertia::tenant()?->key;
    }
}

/** Subclass that declares its own typed `$delay` (pomely's workaround) must keep working. */
class TypedDelayProbeUseCase extends UseCase
{
    public mixed $delay = null;

    public function execute(): void {}
}

/** Jobs the bus pushed through Queue::later() (QueueFake keeps them in a protected array). */
function delayedJobs(): array
{
    return (fn () => $this->delayed)->call(Queue::getFacadeRoot());
}

it('delay() queues the use case with the delay instead of recursing', function () {
    Queue::fake();

    (new DelayedProbeUseCase)->delay(30);

    Queue::assertPushed(DelayedProbeUseCase::class, fn ($job) => $job->delay === 30);
    expect(delayedJobs()[DelayedProbeUseCase::class] ?? [])->toHaveCount(1);
});

it('delay() works when the subclass declares a typed $delay property', function () {
    Queue::fake();

    $at = now()->addMinutes(5);
    (new TypedDelayProbeUseCase)->delay($at);

    Queue::assertPushed(TypedDelayProbeUseCase::class, fn ($job) => $job->delay == $at);
    expect(delayedJobs()[TypedDelayProbeUseCase::class] ?? [])->toHaveCount(1);
});

it('sync queue: running a use case restores the previously active tenant', function () {
    bootOrgOpenMode($this->app);
    Tenant::create(['key' => 'machali', 'name' => 'Machalí', 'status' => 'active']);
    config()->set('queue.default', 'sync');

    Innertia::activate('machali');
    $job = new DelayedProbeUseCase;
    Innertia::activate('rancagua');
    $job->onQueue(); // captures 'rancagua' and runs synchronously

    expect(DelayedProbeUseCase::$seenTenant)->toBe('rancagua')
        ->and(Innertia::tenant()?->key)->toBe('rancagua');

    // A job captured under another tenant runs there and the caller's tenant comes back.
    $other = new DelayedProbeUseCase;
    (fn () => $this->__tenantKey = 'machali')->call($other);
    $other->handle();

    expect(DelayedProbeUseCase::$seenTenant)->toBe('machali')
        ->and(Innertia::tenant()?->key)->toBe('rancagua');
});

it('a worker without a previous tenant leaves the context empty after the job', function () {
    bootOrgOpenMode($this->app);
    Innertia::deactivate();

    $job = new DelayedProbeUseCase;
    (fn () => $this->__tenantKey = 'rancagua')->call($job);
    $job->handle();

    expect(DelayedProbeUseCase::$seenTenant)->toBe('rancagua')->and(Innertia::tenant())->toBeNull();
});
