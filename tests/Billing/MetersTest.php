<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Innertia\Ai\Facades\Ai;
use Innertia\Ai\Models\AiModel;
use Innertia\Billing\Billing;
use Innertia\Billing\MeterDefinition;
use Innertia\Billing\Meters;
use Innertia\Billing\Models\UsageRecord;
use Innertia\Billing\Support\BillingSettings;
use Innertia\Facades\Innertia;

beforeEach(function () {
    bootBillingMode($this->app);
    $this->tenant = billingTenant();
});

it('records idempotently by source and resolves the tenant by model, id, key or the active one', function () {
    $a = Meters::record($this->tenant, 'sms.messages', 1, null, [], 'sms:1');
    $dup = Meters::record((string) $this->tenant->id, 'sms.messages', 1, null, [], 'sms:1');
    $byMeta = Meters::record('rancagua', 'sms.messages', 2, '2026-11-02 10:00:00', ['source_id' => 'sms:2']);
    $byMetaDup = Meters::record('rancagua', 'sms.messages', 2, null, ['source_id' => 'sms:2']);
    Innertia::activate('rancagua');
    $active = Billing::record('whatsapp.messages', 3, 'wa:1');

    expect($a)->not->toBeNull()
        ->and($dup)->toBeNull()
        ->and($byMeta->quantity)->toBe(2.0)
        ->and($byMetaDup)->toBeNull()
        ->and($active->tenant_id)->toBe((string) $this->tenant->id)
        ->and(UsageRecord::count())->toBe(3);
});

it('ignores meters the product did not activate and rejects unknown ones', function () {
    config()->set('innertia.billing.meters', ['voice.minutes']);

    expect(Meters::record($this->tenant, 'sms.messages', 1))->toBeNull()
        ->and(Meters::record($this->tenant, 'voice.minutes', 1.5)->quantity)->toBe(1.5)
        ->and(fn () => Meters::record($this->tenant, 'nope.meter', 1))->toThrow(InvalidArgumentException::class);

    Meters::register(new MeterDefinition('pages.printed', 'Páginas impresas', 'página'));
    config()->set('innertia.billing.meters', null);
    expect(Meters::record($this->tenant, 'pages.printed', 4)->quantity)->toBe(4.0)
        ->and(array_keys(Meters::active()))->toContain('ai.cost', 'voice.minutes', 'users.active', 'pages.printed');
});

it('is a no-op while billing is off', function () {
    config()->set('innertia.billing.enabled', false);

    expect(Meters::record($this->tenant, 'sms.messages', 1, null, [], 'off:1'))->toBeNull()
        ->and(Billing::subscription($this->tenant))->toBeNull()
        ->and(UsageRecord::count())->toBe(0);
});

it('meters every priced AI usage of a space as ai.cost at the platform USD→CLP rate', function () {
    $dir = sys_get_temp_dir() . '/innertia-billing-ai-' . uniqid();
    File::ensureDirectoryExists($dir);
    $this->artisan('innertia:ai:install', ['--path' => $dir])->assertSuccessful();
    $migration = require glob($dir . '/*_create_ai_tables*.php')[0];
    $migration->up();
    BillingSettings::save(['usd_clp_rate' => 1000]);
    AiModel::create(['provider_id' => '00000000-0000-0000-0000-000000000001', 'key' => 'm1', 'name' => 'M1', 'price_input_per_mtok' => 3]);

    Innertia::activate('rancagua');
    $event = Ai::recordUsage('call_summary', ['input_tokens' => 1_000_000], 'm1');
    Ai::recordUsage('assistant', ['input_tokens' => 10], 'unpriced');   // no cost → no meter
    Innertia::deactivate();
    Ai::recordUsage('assistant', ['input_tokens' => 1_000_000], 'm1');  // no tenant → no meter

    $record = UsageRecord::sole();
    expect($record->meter)->toBe('ai.cost')
        ->and($record->quantity)->toBe(3.0)
        ->and($record->cost)->toBe(3000)
        ->and($record->source_ref)->toBe('ai:' . $event->id)
        ->and($record->meta['feature'])->toBe('call_summary');

    $migration->down();
    File::deleteDirectory($dir);
});

it('opens a trial with the default plan on self sign-up', function () {
    $plan = billingPlan(['key' => 'starter', 'trial_days' => 14]);
    config()->set('innertia.billing.default_plan', 'starter');
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'America/Santiago'));

    $tenant = billingTenant('nuevo');
    $sub = \Innertia\Billing\UseCases\StartDefaultSubscription::for($tenant);

    expect($sub->plan_id)->toBe($plan->id)
        ->and($sub->status->value)->toBe('trialing')
        ->and($sub->trial_ends_at->format('Y-m-d'))->toBe('2026-12-02');

    config()->set('innertia.billing.default_plan', 'missing');
    expect(\Innertia\Billing\UseCases\StartDefaultSubscription::for(billingTenant('otro')))->toBeNull();
});

it('registers the billing notice types and the dunning/close schedule in the billing time zone', function () {
    expect(\Innertia\Notifications\NoticeTypes::get('billing.suspended')?->critical)->toBeTrue();

    $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
    (new \Innertia\Billing\BillingServiceProvider($this->app))->schedule($schedule);
    $events = collect($schedule->events())->filter(fn ($e) => str_contains((string) $e->description, 'innertia:billing'));

    expect($events->pluck('description')->unique()->values()->all())->toBe(['innertia:billing:close', 'innertia:billing:dunning', 'innertia:billing:reminders'])
        ->and($events->first()->timezone)->toBe('America/Santiago');
});
