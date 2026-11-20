<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Innertia\Billing\Enums\SubscriptionStatus;
use Innertia\Billing\Meters;
use Innertia\Billing\Models\Adjustment;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Statement;
use Innertia\Billing\Models\UsageRecord;
use Innertia\Billing\UseCases\CloseBillingPeriods;
use Innertia\Billing\UseCases\CloseSubscriptionPeriod;
use Innertia\Billing\UseCases\StartSubscription;
use Innertia\Billing\UseCases\UpdateSubscription;
use Innertia\Exceptions\ConflictException;
use Innertia\Saas\Enums\TenantEnvironment;
use Innertia\Saas\UseCases\SetTenantEnvironment;

beforeEach(function () {
    bootBillingMode($this->app);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'America/Santiago'));
    billingSeats(7);
    $this->tenant = billingTenant();
});

function startSub(string $planId, array $args = []): \Innertia\Billing\Models\Subscription
{
    return (new StartSubscription(tenantKey: $args['key'] ?? 'rancagua', planId: $planId,
        overrides: $args['overrides'] ?? [], startsAt: $args['starts_at'] ?? '2026-11-01', trialDays: array_key_exists('trial_days', $args) ? $args['trial_days'] : 0))->execute();
}

it('computes base, extra seats, metered usage over the included, AI cost with markup, adjustments and VAT', function () {
    $plan = billingPlan([], [
        ['meter' => 'whatsapp.messages', 'included_quantity' => 100, 'unit_price' => 15],
        ['meter' => 'ai.cost', 'included_quantity' => 1000, 'markup_percent' => 20],
    ]);
    $sub = startSub($plan->id);

    $tid = (string) $this->tenant->id;
    foreach (range(1, 3) as $i) {
        Meters::record($tid, 'whatsapp.messages', 50, '2026-11-10 10:00:00', sourceRef: "wa:{$i}");
    }
    Meters::record($tid, 'ai.cost', 4.2, '2026-11-15 10:00:00', sourceRef: 'ai:1', cost: 3990);       // 4.2 USD ≈ $3.990
    Meters::record($tid, 'sms.messages', 10, '2026-11-15 10:00:00', sourceRef: 'sms:1');              // not in plan: not charged
    Adjustment::create(['tenant_id' => $tid, 'type' => 'discount', 'percent' => 10, 'recurring' => true, 'reason' => 'Descuento convenio']);
    Adjustment::create(['tenant_id' => $tid, 'type' => 'one_off', 'amount' => 25000, 'reason' => 'Instalación']);

    $statements = (new CloseSubscriptionPeriod($sub->id, '2026-12-01'))->execute();

    expect($statements)->toHaveCount(1);
    $s = $statements[0]->fresh('lines');
    $lines = $s->lines->keyBy(fn ($l) => $l->kind->value . ':' . ($l->meter ?? $l->description));

    // base 100.000 + seats (7−5)×5.000 = 10.000 + WA (150−100)×15 = 750 + AI 3.990×1.2−1.000 = 3.788
    expect($lines['base:Plan Pro']->amount)->toBe(100000)
        ->and($lines['seats:Usuarios adicionales']->amount)->toBe(10000)
        ->and($lines['meter:whatsapp.messages']->amount)->toBe(750)
        ->and($lines['meter:whatsapp.messages']->quantity)->toBe(150.0)
        ->and($lines['meter:ai.cost']->amount)->toBe(3788)
        ->and($lines['adjustment:Descuento convenio']->amount)->toBe(-11454)   // 10 % of 114.538 (half up)
        ->and($lines['adjustment:Instalación']->amount)->toBe(25000)
        ->and($s->subtotal)->toBe(128084)
        ->and($s->tax)->toBe(24336)                                            // 19 % rounded once
        ->and($s->total)->toBe(152420)
        ->and($s->period_start->format('Y-m-d'))->toBe('2026-11-01')
        ->and($s->period_end->format('Y-m-d'))->toBe('2026-11-30');

    expect(UsageRecord::where('meter', 'sms.messages')->value('statement_id'))->toBeNull()
        ->and(UsageRecord::where('meter', 'whatsapp.messages')->whereNull('statement_id')->count())->toBe(0)
        ->and(Adjustment::where('reason', 'Instalación')->value('consumed_at'))->not->toBeNull()
        ->and(Adjustment::where('reason', 'Descuento convenio')->value('consumed_at'))->toBeNull();

    $sub->refresh();
    expect($sub->current_period_start->format('Y-m-d'))->toBe('2026-12-01')
        ->and($sub->current_period_end->format('Y-m-d'))->toBe('2026-12-31');
});

it('uses negotiated overrides per field and per meter', function () {
    $plan = billingPlan([], [['meter' => 'voice.minutes', 'included_quantity' => 0, 'unit_price' => 30]]);
    $sub = startSub($plan->id, ['overrides' => ['base_price' => 80000, 'included_seats' => 10, 'meters' => ['voice.minutes' => ['unit_price' => 20]]]]);
    Meters::record($this->tenant, 'voice.minutes', 12.5, '2026-11-20 09:00:00', sourceRef: 'call:1');

    $s = (new CloseSubscriptionPeriod($sub->id, '2026-12-01'))->execute()[0];

    expect($s->lines->mapWithKeys(fn ($l) => [$l->kind->value => $l->amount])->all())->toMatchArray(['base' => 80000, 'meter' => 250])   // 12.5 × 20, no seats line
        ->and($s->lines->where('kind.value', 'seats'))->toBeEmpty();
});

it('is idempotent: a second run or a held lock never duplicates the statement', function () {
    $sub = startSub(billingPlan()->id);

    (new CloseSubscriptionPeriod($sub->id, '2026-12-01'))->execute();
    (new CloseSubscriptionPeriod($sub->id, '2026-12-01'))->execute();
    (new CloseBillingPeriods('2026-12-01'))->execute();

    $this->travelTo(CarbonImmutable::parse('2027-01-05 12:00:00', 'America/Santiago'));
    $lock = Cache::lock("innertia:billing:close:{$sub->id}", 60);
    $lock->get();
    expect((new CloseSubscriptionPeriod($sub->id, '2027-01-05'))->execute())->toBe([]);
    $lock->release();

    expect(Statement::count())->toBe(1)
        ->and((new CloseSubscriptionPeriod($sub->id, '2027-01-05'))->execute())->toHaveCount(1)
        ->and(Statement::count())->toBe(2);
});

it('catches up missed periods and applies a plan change from the next period', function () {
    $basic = billingPlan(['key' => 'basic', 'name' => 'Básico', 'base_price' => 50000, 'seat_price' => 0]);
    $pro = billingPlan(['key' => 'pro', 'name' => 'Pro', 'base_price' => 90000, 'seat_price' => 0]);
    $sub = startSub($basic->id, ['starts_at' => '2026-09-15']);
    (new UpdateSubscription($sub, ['plan_id' => $pro->id]))->execute();

    $created = (new CloseSubscriptionPeriod($sub->id, '2026-11-20'))->execute();

    expect(collect($created)->map(fn ($s) => [$s->period_start->format('Y-m-d'), $s->period_end->format('Y-m-d'), $s->subtotal])->all())->toBe([
        ['2026-09-15', '2026-10-14', 50000],
        ['2026-10-15', '2026-11-14', 90000],
    ]);
    expect($sub->fresh()->plan_id)->toBe($pro->id)
        ->and($sub->fresh()->pending_plan_id)->toBeNull();
});

it('does not bill the trial and starts the paid periods the day after', function () {
    $plan = billingPlan(['trial_days' => 14, 'seat_price' => 0], [['meter' => 'sms.messages', 'unit_price' => 10]]);
    $sub = startSub($plan->id, ['trial_days' => null]);
    Meters::record($this->tenant, 'sms.messages', 5, '2026-11-05 10:00:00', sourceRef: 'trial-sms');

    expect($sub->status)->toBe(SubscriptionStatus::Trialing)
        ->and($sub->current_period_end->format('Y-m-d'))->toBe('2026-11-14');

    $created = (new CloseSubscriptionPeriod($sub->id, '2026-11-15'))->execute();
    $sub->refresh();

    expect($created)->toBe([])
        ->and($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->current_period_start->format('Y-m-d'))->toBe('2026-11-15')
        ->and($sub->current_period_end->format('Y-m-d'))->toBe('2026-12-14');

    $s = (new CloseSubscriptionPeriod($sub->id, '2026-12-15'))->execute()[0];
    expect($s->lines->where('kind.value', 'late_usage'))->toBeEmpty();   // trial usage is never billed
});

it('bills usage recorded after the close as late usage in the next period', function () {
    $plan = billingPlan(['seat_price' => 0], [['meter' => 'sms.messages', 'included_quantity' => 100, 'unit_price' => 10]]);
    $sub = startSub($plan->id);
    (new CloseSubscriptionPeriod($sub->id, '2026-12-01'))->execute();

    Meters::record($this->tenant, 'sms.messages', 7, '2026-11-29 23:00:00', sourceRef: 'late-1');
    $s = (new CloseSubscriptionPeriod($sub->id, '2027-01-01'))->execute()[0];
    $late = $s->lines->firstWhere('kind.value', 'late_usage');

    expect($late->amount)->toBe(70)      // no included allowance for late usage
        ->and($late->quantity)->toBe(7.0);
});

it('groups statements per space or consolidated per client', function () {
    $plan = billingPlan(['seat_price' => 0]);
    $client = $this->tenant->client;
    $other = billingTenant('machali', $client->id);
    startSub($plan->id);
    startSub($plan->id, ['key' => 'machali', 'starts_at' => '2026-11-10']);

    (new CloseBillingPeriods('2026-12-15'))->execute();

    $invoice = Invoice::sole();
    expect($invoice->tenant_id)->toBeNull()
        ->and($invoice->statements)->toHaveCount(2)
        ->and($invoice->subtotal)->toBe(200000)
        ->and($invoice->period_start->format('Y-m-d'))->toBe('2026-11-01')
        ->and($invoice->period_end->format('Y-m-d'))->toBe('2026-12-09');

    $client->update(['invoicing_mode' => 'per_space']);
    (new CloseBillingPeriods('2027-01-15'))->execute();
    expect(Invoice::whereNotNull('tenant_id')->pluck('tenant_id')->sort()->values()->all())
        ->toBe([(string) $this->tenant->id, (string) $other->id]);
});

it('never bills testing spaces and a billed space cannot go back to testing', function () {
    $plan = billingPlan();
    billingTenant('pruebas');
    (new SetTenantEnvironment('pruebas', TenantEnvironment::Testing))->execute();

    expect(fn () => startSub($plan->id, ['key' => 'pruebas']))->toThrow(ConflictException::class);

    $sub = startSub($plan->id);
    (new CloseSubscriptionPeriod($sub->id, '2026-12-01'))->execute();
    expect(fn () => (new SetTenantEnvironment('rancagua', TenantEnvironment::Testing))->execute())->toThrow(ConflictException::class);
});
