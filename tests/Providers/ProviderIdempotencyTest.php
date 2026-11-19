<?php

require_once __DIR__ . '/../Platform/Events/helpers/SampleEvents.php';

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Innertia\InnertiaAppProvider;
use Innertia\InnertiaOpenProvider;
use Innertia\InnertiaSaasProvider;
use Innertia\InnertiaServiceProvider;
use Innertia\Notifications\Events\NotificationSent;
use Innertia\Platform\Events\IsDomainEvent;
use Innertia\Platform\Listeners\DomainEventRouter;
use Innertia\Saas\Settings\SaasSettingsService;
use Innertia\Settings\AppSettingsService;
use Innertia\Webhooks\WebhookService;

/*
 * Products in open/app/saas mode get InnertiaServiceProvider auto-discovered AND register the mode
 * provider (a subclass) in bootstrap/providers.php. Laravel dedupes providers by exact class name, so
 * both run: the shared boot (listeners, Gate::before, model hooks, routes) must happen only once.
 */

class WebhookChannelEvent extends \Innertia\Platform\Events\DomainEvent
{
    public function key(): \Innertia\Platform\Events\DomainEventKey
    {
        return SampleFooEvent::Created;
    }

    public function channels(): array
    {
        return ['webhook'];
    }
}

function useInnertiaProviders(object $test, ?array $providers): void
{
    BaseTestCase::$innertiaProviders = $providers;
    (fn () => $this->refreshApplication())->call($test);
    config()->set('broadcasting.default', 'null');
}

afterEach(function () {
    BaseTestCase::$innertiaProviders = null;
});

dataset('provider setups', [
    'open: discovered + InnertiaOpenProvider' => [[InnertiaServiceProvider::class, InnertiaOpenProvider::class], 'open'],
    'open: InnertiaOpenProvider + discovered' => [[InnertiaOpenProvider::class, InnertiaServiceProvider::class], 'open'],
    'open: only InnertiaOpenProvider'         => [[InnertiaOpenProvider::class], 'open'],
    'app: discovered + InnertiaAppProvider'   => [[InnertiaServiceProvider::class, InnertiaAppProvider::class], 'app'],
    'saas: discovered + InnertiaSaasProvider' => [[InnertiaServiceProvider::class, InnertiaSaasProvider::class], 'saas'],
    'api: discovered + InnertiaApiProvider'   => [[InnertiaServiceProvider::class, \Innertia\InnertiaApiProvider::class], 'api'],
    'discovered only'                         => [[InnertiaServiceProvider::class], 'app'],
]);

it('routes a DomainEvent with the webhook channel exactly once', function (array $providers, string $mode) {
    useInnertiaProviders($this, $providers);

    $webhooks = Mockery::mock(WebhookService::class);
    $webhooks->shouldReceive('dispatchForEvent')->once();
    app()->instance(WebhookService::class, $webhooks);

    event(new WebhookChannelEvent());
})->with('provider setups');

it('registers the router and the EventBus bridge once', function (array $providers, string $mode) {
    useInnertiaProviders($this, $providers);

    $raw = Event::getRawListeners()[IsDomainEvent::class] ?? [];
    $routers = array_filter($raw, fn ($l) => $l === DomainEventRouter::class);

    expect($routers)->toHaveCount(1)
        ->and($raw)->toHaveCount(2); // router + EventBus bridge
})->with('provider setups');

it('fans out to EventBus listeners once', function (array $providers, string $mode) {
    useInnertiaProviders($this, $providers);

    $calls = 0;
    \Innertia\Facades\Events::listen(SampleFooEvent::Created, function () use (&$calls) {
        $calls++;
    });

    event(new SampleFooCreated('once'));

    expect($calls)->toBe(1);
})->with('provider setups');

it('keeps the mode of the mode provider', function (array $providers, string $mode) {
    useInnertiaProviders($this, $providers);

    if ($providers !== [InnertiaServiceProvider::class]) {
        expect(config('innertia.mode'))->toBe($mode);
    }

    $settings = app(AppSettingsService::class);
    in_array(config('innertia.mode'), ['saas', 'open'], true)
        ? expect($settings)->toBeInstanceOf(SaasSettingsService::class)
        : expect($settings)->not->toBeInstanceOf(SaasSettingsService::class);
})->with('provider setups');

it('registers a single Gate::before, MigrationsEnded and Notification::created hook', function (array $providers, string $mode) {
    useInnertiaProviders($this, $providers);

    $gate = Gate::getFacadeRoot();
    $before = (fn () => $this->beforeCallbacks)->call($gate);
    // InnertiaServiceProvider + AuthServiceProvider each add one.
    expect($before)->toHaveCount(2);

    $migrations = Event::getRawListeners()[\Illuminate\Database\Events\MigrationsEnded::class] ?? [];
    expect($migrations)->toHaveCount(1);

    $created = Event::getRawListeners()['eloquent.created: ' . \Innertia\Notifications\Models\Notification::class] ?? [];
    expect($created)->toHaveCount(1);
})->with('provider setups');

it('records which provider booted the shared setup', function () {
    useInnertiaProviders($this, [InnertiaServiceProvider::class, InnertiaOpenProvider::class]);

    expect(app()->bound('innertia.provider.booted'))->toBeTrue();
});
