<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Innertia\Billing\Meters;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Routes;
use Innertia\Facades\Innertia;
use Innertia\Saas\Enums\TenantEnvironment;
use Innertia\Saas\UseCases\SetTenantEnvironment;

beforeEach(function () {
    bootBillingMode($this->app);
    Mail::fake();
    billingSeats(0);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'America/Santiago'));
    $this->tenant = billingTenant();
    $this->tenant->client->update(['billing_email' => 'pagos@x.cl', 'invoicing_mode' => 'consolidated']);
    $this->other = billingTenant('machali', (string) $this->tenant->client_id);
    Route::prefix('p')->group(function () {
        Routes::platform();
        Routes::platformWrite();
    });
    Route::prefix('b')->group(fn () => Routes::backoffice());
});

it('manages plans and the meter catalog', function () {
    $this->getJson('/p/billing/meters')->assertOk()->assertJsonFragment(['key' => 'ai.cost', 'valued_by_cost' => true]);

    $id = $this->postJson('/p/billing/plans', [
        'key' => 'pro', 'name' => 'Pro', 'base_price' => 120000, 'included_seats' => 5, 'seat_price' => 4000,
        'meters' => [['meter' => 'voice.minutes', 'included_quantity' => 500, 'unit_price' => 25], ['meter' => 'ai.cost', 'markup_percent' => 30]],
    ])->assertCreated()->assertJsonPath('meters.0.label', 'Minutos de voz salientes')->json('id');

    $this->patchJson("/p/billing/plans/{$id}", ['base_price' => 130000, 'meters' => [['meter' => 'ai.cost', 'markup_percent' => 25]]])
        ->assertOk()->assertJsonPath('base_price', 130000)->assertJsonCount(1, 'meters');

    $this->postJson('/p/billing/plans', ['key' => 'pro', 'name' => 'X', 'meters' => [['meter' => 'nope']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['key', 'meters.0.meter']);
    $this->getJson('/p/billing/plans')->assertOk()->assertJsonCount(1, 'data');
});

it('runs the platform flow: subscription, close, issue, pdf, payment and client totals', function () {
    $plan = billingPlan(['seat_price' => 0], [['meter' => 'sms.messages', 'unit_price' => 10]]);

    $this->postJson('/p/billing/spaces/rancagua/subscription', ['plan_id' => $plan->id, 'payment_method' => 'invoice'])
        ->assertCreated()->assertJsonPath('status', 'active')->assertJsonPath('current_period_end', '2026-11-30');
    $this->postJson('/p/billing/spaces/machali/subscription', ['plan_id' => $plan->id])->assertCreated();
    expect(fn () => $this->withoutExceptionHandling()->postJson('/p/billing/spaces/rancagua/subscription', ['plan_id' => $plan->id]))
        ->toThrow(\Innertia\Exceptions\ConflictException::class);
    $this->withExceptionHandling();

    Meters::record($this->tenant, 'sms.messages', 20, '2026-11-10 10:00:00', sourceRef: 's1');
    $this->getJson('/p/billing/spaces/rancagua')->assertOk()
        ->assertJsonPath('usage.0.quantity', 20)
        ->assertJsonPath('estimate.subtotal', 100200);

    $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00:00', 'America/Santiago'));
    $this->postJson('/p/billing/run/close')->assertOk()->assertJsonPath('statements', 2);

    $draft = $this->getJson('/p/billing/invoices?status=draft')->assertOk()->json('data.0');
    expect($draft['consolidated'])->toBeTrue()->and($draft['total'])->toBe(238238);

    $this->getJson("/p/billing/invoices/{$draft['id']}")->assertOk()->assertJsonCount(2, 'statements');
    $this->postJson("/p/billing/invoices/{$draft['id']}/issue", ['folio' => '123'])->assertOk()
        ->assertJsonPath('status', 'issued')->assertJsonPath('display_number', 'F-000001')->assertJsonPath('folio', '123');

    $pdf = $this->get("/p/billing/invoices/{$draft['id']}/pdf")->assertOk();
    expect($pdf->headers->get('content-type'))->toBe('application/pdf');

    $this->postJson('/p/billing/payments', ['client_id' => $this->tenant->client_id, 'amount' => 100000, 'reference' => 'trf'])
        ->assertCreated()->assertJsonPath('allocations.0.amount', 100000);

    $this->getJson('/p/billing/clients/totals')->assertOk()
        ->assertJsonPath('totals.mrr', 200000)
        ->assertJsonPath('totals.balance', 138238)
        ->assertJsonPath('data.0.spaces.0.subscription.status', 'active');

    $this->patchJson('/p/billing/spaces/rancagua/subscription', ['payment_method' => 'automatic'])->assertOk()->assertJsonPath('payment_method', 'automatic');
    $this->postJson('/p/billing/spaces/rancagua/subscription/suspend')->assertOk()->assertJsonPath('status', 'suspended');
    $this->postJson('/p/billing/spaces/rancagua/subscription/reactivate')->assertOk()->assertJsonPath('status', 'active');
});

it('edits platform billing settings', function () {
    $this->putJson('/p/billing/settings', ['usd_clp_rate' => 980, 'transfer_instructions' => 'Banco X cta 123'])->assertOk()
        ->assertJsonPath('usd_clp_rate', 980);
    $this->getJson('/p/billing/settings')->assertJsonPath('transfer_instructions', 'Banco X cta 123');
    expect(\Innertia\Billing\Support\ExchangeRate::usdToClp())->toBe(980.0);
});

it('shows the backoffice only its own space: plan, usage, invoices with its part, payments', function () {
    $plan = billingPlan(['seat_price' => 0]);
    $this->postJson('/p/billing/spaces/rancagua/subscription', ['plan_id' => $plan->id]);
    $this->postJson('/p/billing/spaces/machali/subscription', ['plan_id' => $plan->id]);
    $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00:00', 'America/Santiago'));
    $this->postJson('/p/billing/run/close');
    $invoice = Invoice::sole();
    $this->postJson("/p/billing/invoices/{$invoice->id}/issue", ['send' => false]);
    $this->postJson('/p/billing/payments', ['client_id' => $this->tenant->client_id, 'amount' => 1000]);

    Innertia::activate('rancagua');
    $this->getJson('/b/billing/overview')->assertOk()
        ->assertJsonPath('subscription.status', 'active')
        ->assertJsonPath('plan.name', 'Pro')
        ->assertJsonPath('balance.open', 237000);
    $this->getJson('/b/billing/invoices')->assertOk()
        ->assertJsonPath('data.0.space_part', 119000)
        ->assertJsonPath('data.0.total', 238000);
    $this->getJson("/b/billing/invoices/{$invoice->id}")->assertOk()->assertJsonCount(1, 'statements')->assertJsonMissingPath('events');
    $this->get("/b/billing/invoices/{$invoice->id}/pdf")->assertOk();
    $this->getJson('/b/billing/payments')->assertOk()->assertJsonPath('data.0.amount', 1000);
});

it('hides other clients invoices and shows the testing strip for testing spaces', function () {
    $foreign = billingTenant('ajeno');
    $plan = billingPlan(['seat_price' => 0]);
    $this->postJson('/p/billing/spaces/ajeno/subscription', ['plan_id' => $plan->id]);
    $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00:00', 'America/Santiago'));
    $this->postJson('/p/billing/run/close');
    $invoice = Invoice::sole();
    $this->postJson("/p/billing/invoices/{$invoice->id}/issue", ['send' => false]);

    Innertia::activate('rancagua');
    $this->getJson('/b/billing/invoices')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/b/billing/invoices/{$invoice->id}")->assertNotFound();
    $this->getJson('/b/billing/overview')->assertOk()->assertJsonPath('subscription', null);

    (new SetTenantEnvironment('rancagua', TenantEnvironment::Testing))->execute();
    Innertia::activate('rancagua');
    $this->getJson('/b/billing/overview')->assertOk()->assertJsonPath('testing', true);
});
