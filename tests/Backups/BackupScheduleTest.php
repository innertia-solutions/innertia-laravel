<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;
use Innertia\Backups\BackupsServiceProvider;
use Innertia\Backups\Support\BackupSettings;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaBackupsMigrateUp();
});

afterEach(fn () => innertiaBackupsMigrateDown());

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

it('takes times and timezone from the platform settings', function () {
    BackupSettings::save(['times' => ['22:15', '02:30'], 'timezone' => 'UTC']);

    $events = backupEvents();
    expect(array_map(fn (Event $e) => $e->expression, $events))->toBe(['30 2 * * *', '15 22 * * *']);
    expect($events[0]->timezone)->toBe('UTC');
});

it('cannot be switched off: config and env do not disable it', function () {
    config(['innertia.backups.schedule.enabled' => false, 'innertia.backups.schedule.times' => []]);

    expect(backupEvents())->toHaveCount(3);
});

it('falls back to defaults when stored times are invalid or empty', function () {
    BackupSettings::save(['times' => ['25:00', 'nope']]);

    expect(backupEvents())->toHaveCount(3);
});

it('still registers with defaults when the settings table is unavailable', function () {
    Schema::dropIfExists('settings');
    \Illuminate\Support\Facades\Cache::flush();

    expect(backupEvents())->toHaveCount(3);
});

it('registers a local backups disk when the product does not declare one', function () {
    expect(config('filesystems.disks.backups.driver'))->toBe('local');
});
