<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Innertia\Backups\BackupsServiceProvider;

function backupEvents(): array
{
    $schedule = new Schedule();
    (new BackupsServiceProvider(app()))->schedule($schedule);

    return array_values(array_filter(
        $schedule->events(),
        fn (Event $e) => str_contains((string) $e->command, 'innertia:backups:run'),
    ));
}

it('registers the default backup schedule three times a day in Chile time', function () {
    $events = backupEvents();

    expect($events)->toHaveCount(3);
    expect(array_map(fn (Event $e) => $e->expression, $events))
        ->toBe(['0 3 * * *', '0 11 * * *', '0 19 * * *']);

    foreach ($events as $event) {
        expect($event->timezone)->toBe('America/Santiago');
        expect($event->withoutOverlapping)->toBeTrue();
        expect($event->onOneServer)->toBeTrue();
    }
});

it('is wired into the app scheduler by default', function () {
    $commands = array_map(fn (Event $e) => (string) $e->command, app(Schedule::class)->events());

    expect(collect($commands)->filter(fn ($c) => str_contains($c, 'innertia:backups:run')))->toHaveCount(3);
});

it('can be disabled by config', function () {
    config(['innertia.backups.schedule.enabled' => false]);

    expect(backupEvents())->toBeEmpty();
});

it('honours custom times and timezone', function () {
    config([
        'innertia.backups.schedule.times'    => ['02:30'],
        'innertia.backups.schedule.timezone' => 'UTC',
    ]);

    $events = backupEvents();
    expect($events)->toHaveCount(1);
    expect($events[0]->expression)->toBe('30 2 * * *');
    expect($events[0]->timezone)->toBe('UTC');
});

it('falls back to defaults when the product overrides the backups block partially', function () {
    // mergeConfigFrom es shallow: un bloque `backups` del producto reemplaza el de la lib.
    config(['innertia.backups' => ['disk' => 'backups']]);

    expect(backupEvents())->toHaveCount(3);
});

it('registers a local backups disk when the product does not declare one', function () {
    expect(config('filesystems.disks.backups.driver'))->toBe('local');
});
