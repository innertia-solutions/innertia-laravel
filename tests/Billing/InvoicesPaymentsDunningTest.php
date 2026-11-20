<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Innertia\Billing\Contracts\PaymentLinkProvider;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Enums\PaymentChannel;
use Innertia\Billing\Enums\PaymentMethod;
use Innertia\Billing\Enums\SubscriptionStatus;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\UseCases\ChangeSubscriptionStatus;
use Innertia\Billing\UseCases\CloseBillingPeriods;
use Innertia\Billing\UseCases\IssueInvoice;
use Innertia\Billing\UseCases\RegisterPayment;
use Innertia\Billing\UseCases\RunDunning;
use Innertia\Billing\UseCases\StartSubscription;
use Innertia\Exceptions\ConflictException;
use Innertia\Facades\Innertia;
use Innertia\Mail\NotificationMail;

beforeEach(function () {
    bootBillingMode($this->app);
    Mail::fake();
    billingSeats(0);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'America/Santiago'));
    $this->tenant = billingTenant();
    $this->client = $this->tenant->client;
    $this->client->update(['billing_email' => 'pagos@cliente.cl', 'legal_name' => 'Cliente SpA', 'tax_id' => '76.111.111-1',
        'payment_terms_days' => 30, 'grace_days' => 15, 'invoicing_mode' => 'per_space']);
    $this->plan = billingPlan(['seat_price' => 0, 'base_price' => 100000]);
    $this->sub = (new StartSubscription('rancagua', $this->plan->id, startsAt: '2026-11-01', trialDays: 0))->execute();
});

function closeAndIssue(string $date, bool $send = true): Invoice
{
    test()->travelTo(CarbonImmutable::parse("{$date} 09:00:00", 'America/Santiago'));
    (new CloseBillingPeriods($date))->execute();
    $draft = Invoice::where('status', 'draft')->latest('created_at')->firstOrFail();

    return (new IssueInvoice($draft, folio: null, send: $send))->execute();
}

it('issues a draft: sequential number, snapshot, due date, email with the PDF', function () {
    $first = closeAndIssue('2026-12-01');
    $second = closeAndIssue('2027-01-01');

    expect($first->status)->toBe(InvoiceStatus::Issued)
        ->and($first->number)->toBe(1)
        ->and($second->number)->toBe(2)
        ->and($first->displayNumber())->toBe('F-000001')
        ->and($first->total)->toBe(119000)
        ->and($first->balance)->toBe(119000)
        ->and($first->billing_data['legal_name'])->toBe('Cliente SpA')
        ->and($first->due_at->diffInDays($first->issued_at, true))->toEqual(30.0)
        ->and($first->events->pluck('event')->all())->toContain('created', 'issued', 'sent');

    Mail::assertQueued(NotificationMail::class, fn ($m) => $m->hasTo('pagos@cliente.cl')
        && count($m->rawAttachments) === 1 && str_starts_with($m->rawAttachments[0]['data'], '%PDF'));

    expect(fn () => (new IssueInvoice($first))->execute())->toThrow(ConflictException::class);
});

it('creates the payment link for automatic subscriptions', function () {
    $this->sub->update(['payment_method' => PaymentMethod::Automatic]);
    app()->instance(PaymentLinkProvider::class, new class implements PaymentLinkProvider {
        public function linkFor(Invoice $invoice): ?array { return ['url' => "https://pay.test/{$invoice->number}", 'charge_id' => 'ch-1']; }
    });

    $invoice = closeAndIssue('2026-12-01');

    expect($invoice->payment_link)->toBe('https://pay.test/1')->and($invoice->charge_id)->toBe('ch-1');
});

it('applies partial payments, one payment over several invoices and keeps the credit', function () {
    $a = closeAndIssue('2026-12-01', send: false);
    $b = closeAndIssue('2027-01-01', send: false);

    (new RegisterPayment((string) $this->client->id, 50000, PaymentChannel::Transfer, 'trf-1', invoiceId: $a->id))->execute();
    expect($a->fresh()->balance)->toBe(69000)->and($a->fresh()->status)->toBe(InvoiceStatus::Issued);

    $payment = (new RegisterPayment((string) $this->client->id, 200000, PaymentChannel::Transfer, 'trf-2'))->execute();

    expect($a->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($b->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($payment->allocations->sum('amount'))->toBe(69000 + 119000)
        ->and($payment->unallocated_amount)->toBe(12000);

    // The credit pays (part of) the next invoice when it is issued.
    $c = closeAndIssue('2027-02-01', send: false);
    expect($c->balance)->toBe(107000)
        ->and(Payment::find($payment->id)->unallocated_amount)->toBe(0)
        ->and($c->events->pluck('event'))->toContain('credit');
});

it('applies a paid payment link once, even if the webhook repeats', function () {
    if (! \Illuminate\Support\Facades\Schema::hasTable('charges')) {
        (require __DIR__ . '/../../database/migrations/payments/create_payments_tables.php')->up();
    }
    $invoice = closeAndIssue('2026-12-01', send: false);
    $charge = \Innertia\Payments\Models\Charge::create([
        'billable_type' => $invoice->getMorphClass(), 'billable_id' => $invoice->id, 'amount' => $invoice->balance,
        'currency' => 'CLP', 'status' => 'paid',
    ]);

    event(new \Innertia\Payments\Events\PaymentPaid($charge));
    event(new \Innertia\Payments\Events\PaymentPaid($charge));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::count())->toBe(1)
        ->and(Payment::first()->method)->toBe(PaymentChannel::Link);
});

it('runs the subscription state machine: past_due, suspended after grace, reactivated by paying', function () {
    $invoice = closeAndIssue('2026-12-01', send: false);

    $this->travelTo(now()->addDays(31));
    (new RunDunning)->execute();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Overdue)
        ->and($this->sub->fresh()->status)->toBe(SubscriptionStatus::PastDue);

    $this->travelTo(now()->addDays(16));
    (new RunDunning)->execute();
    expect($this->sub->fresh()->status)->toBe(SubscriptionStatus::Suspended)
        ->and($this->sub->fresh()->suspended_manually)->toBeFalse();

    (new RegisterPayment((string) $this->client->id, $invoice->total))->execute();
    expect($this->sub->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('keeps a manual suspension until reactivated by hand', function () {
    (new ChangeSubscriptionStatus($this->sub, 'suspend'))->execute();
    (new RunDunning)->execute();
    expect($this->sub->fresh()->status)->toBe(SubscriptionStatus::Suspended);

    (new ChangeSubscriptionStatus($this->sub->fresh(), 'reactivate'))->execute();
    expect($this->sub->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($this->sub->fresh()->suspended_manually)->toBeFalse();
});

it('puts a suspended space in read-only mode (402 on writes, reads and billing routes pass)', function () {
    Route::middleware('billing.enforce')->group(function () {
        Route::get('/bo/things', fn () => ['ok' => true]);
        Route::post('/bo/things', fn () => ['ok' => true]);
        Route::post('/bo/billing/pay', fn () => ['ok' => true]);
    });
    Innertia::activate('rancagua');

    $this->postJson('/bo/things')->assertOk();

    (new ChangeSubscriptionStatus($this->sub, 'suspend'))->execute();

    $this->postJson('/bo/things')->assertStatus(402)->assertJsonPath('code', 'subscription_suspended');
    $this->getJson('/bo/things')->assertOk()->assertHeader('X-Billing-Status', 'suspended');
    $this->postJson('/bo/billing/pay')->assertOk();
});

it('consolidated invoices put every space past due and the payment reactivates all of them', function () {
    $this->client->update(['invoicing_mode' => 'consolidated']);
    billingTenant('machali', (string) $this->client->id);
    $other = (new StartSubscription('machali', $this->plan->id, startsAt: '2026-11-01', trialDays: 0))->execute();

    $invoice = closeAndIssue('2026-12-01', send: false);
    expect($invoice->tenant_id)->toBeNull()->and($invoice->total)->toBe(238000);

    $this->travelTo(now()->addDays(31));
    (new RunDunning)->execute();
    expect($this->sub->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($other->fresh()->status)->toBe(SubscriptionStatus::PastDue);

    (new RegisterPayment((string) $this->client->id, 238000))->execute();
    expect($this->sub->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($other->fresh()->status)->toBe(SubscriptionStatus::Active);
});
