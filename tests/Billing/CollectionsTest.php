<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Innertia\Billing\Enums\AdjustmentType;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Enums\PaymentChannel;
use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\LateFee;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\Models\Reminder;
use Innertia\Billing\Models\Statement;
use Innertia\Billing\Routes;
use Innertia\Billing\Support\BillingSettings;
use Innertia\Billing\UseCases\CloseBillingPeriods;
use Innertia\Billing\UseCases\CreateAdjustment;
use Innertia\Billing\UseCases\CreateCreditNote;
use Innertia\Billing\UseCases\IssueInvoice;
use Innertia\Billing\UseCases\RegisterPayment;
use Innertia\Billing\UseCases\ReissueInvoice;
use Innertia\Billing\UseCases\RunDunning;
use Innertia\Billing\UseCases\SendPaymentReminders;
use Innertia\Billing\UseCases\StartSubscription;
use Innertia\Billing\UseCases\VoidInvoice;
use Innertia\Billing\UseCases\WaiveLateFees;
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
    Route::prefix('p')->group(function () {
        Routes::platform();
        Routes::platformWrite();
    });
    Route::prefix('r')->group(fn () => Routes::platform());
    Route::prefix('b')->group(fn () => Routes::backoffice());
});

function collectAt(string $date): void
{
    test()->travelTo(CarbonImmutable::parse("{$date} 09:00:00", 'America/Santiago'));
}

/** The lib's ConflictException is rendered by the product handler: assert it is thrown. */
function conflict(\Closure $call): void
{
    test()->withoutExceptionHandling();
    try {
        expect($call)->toThrow(ConflictException::class);
    } finally {
        test()->withExceptionHandling();
    }
}

/** Closes at $date and issues the new draft (no email). */
function closeIssue(string $date): Invoice
{
    collectAt($date);
    (new CloseBillingPeriods($date))->execute();

    return (new IssueInvoice(Invoice::where('status', 'draft')->latest('created_at')->firstOrFail(), send: false))->execute();
}

it('creates space and client adjustments by endpoint, applies them at the close and cancels pending ones', function () {
    $other = billingTenant('machali', (string) $this->client->id);
    (new StartSubscription('machali', $this->plan->id, startsAt: '2026-11-01', trialDays: 0))->execute();

    $this->postJson('/p/billing/adjustments', ['tenant_key' => 'rancagua', 'type' => 'discount', 'amount' => 10000, 'recurring' => true,
        'starts_at' => '2026-11-01', 'ends_at' => '2026-12-31', 'reason' => 'Descuento de lanzamiento'])
        ->assertCreated()->assertJsonPath('scope', 'space')->assertJsonPath('status', 'pending');
    $this->postJson('/p/billing/adjustments', ['client_id' => $this->client->id, 'type' => 'discount', 'percent' => 10, 'recurring' => true, 'reason' => 'Convenio marco'])
        ->assertCreated()->assertJsonPath('scope', 'client');
    conflict(fn () => $this->postJson('/p/billing/adjustments', ['client_id' => $this->client->id, 'type' => 'discount', 'amount' => 5000, 'reason' => 'x']));
    $oneOff = $this->postJson('/p/billing/adjustments', ['tenant_key' => 'machali', 'type' => 'one_off', 'amount' => 20000, 'reason' => 'Instalación'])
        ->assertCreated()->json('id');
    $this->postJson("/p/billing/adjustments/{$oneOff}/cancel", ['reason' => 'Error de digitación'])->assertOk()->assertJsonPath('status', 'canceled');

    collectAt('2026-12-01');
    (new CloseBillingPeriods('2026-12-01'))->execute();

    $rancagua = Statement::where('tenant_id', (string) $this->tenant->id)->sole();
    $machali = Statement::where('tenant_id', (string) $other->id)->sole();
    // 100000 − 10000 − 10% of 100000 = 80000 net; machali only the client discount (one-off canceled).
    expect($rancagua->subtotal)->toBe(80000)->and($rancagua->lines->pluck('description')->all())->toContain('Descuento de lanzamiento', 'Convenio marco')
        ->and($machali->subtotal)->toBe(90000);

    $this->getJson("/p/billing/adjustments?client_id={$this->client->id}")->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/p/billing/adjustments?tenant_key=rancagua')->assertOk()->assertJsonCount(2, 'data');
    expect(Activity::where('subject_type', 'adjustment')->pluck('action')->all())->toContain('created', 'canceled');
});

it('applies credit notes to an open invoice, to the client balance or to the next period', function () {
    $a = closeIssue('2026-12-01');

    $note = $this->postJson('/p/billing/credit-notes', ['client_id' => $this->client->id, 'amount' => 19000, 'reason' => 'Caída del servicio',
        'target' => 'invoice', 'invoice_id' => $a->id])->assertCreated()
        ->assertJsonPath('display_number', 'NC-000001')->assertJsonPath('credit_note', true)->json();
    expect($a->fresh()->balance)->toBe(100000)
        ->and($a->fresh()->events->pluck('event')->all())->toContain('credit_note')
        ->and(Payment::find($note['payment_id'])->method)->toBe(PaymentChannel::Credit);

    // More than the balance: the rest stays as client credit.
    (new CreateCreditNote((string) $this->client->id, 120000, 'Compensación', 'invoice', $a->id))->execute();
    expect($a->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and((int) Payment::where('client_id', $this->client->id)->sum('unallocated_amount'))->toBe(20000);

    // Next period: a net credit line in the next charge.
    $this->postJson('/p/billing/credit-notes', ['client_id' => $this->client->id, 'amount' => 30000, 'reason' => 'Bonificación',
        'target' => 'next_period', 'tenant_key' => 'rancagua'])->assertCreated()->assertJsonPath('display_number', 'NC-000003');
    $b = closeIssue('2027-01-01');
    // 100000 − 30000 = 70000 net → 83300; the 20000 credit pays part of it.
    expect($b->total)->toBe(83300)->and($b->balance)->toBe(63300);

    $this->postJson('/p/billing/credit-notes', ['client_id' => $this->client->id, 'amount' => 1000, 'reason' => 'x', 'target' => 'next_period'])
        ->assertUnprocessable();
});

it('charges late fee interest on overdue invoices with basis, cap and exemption', function () {
    $this->client->update(['late_fee_percent' => 3]);   // 3% monthly = 0.1% per day
    $a = closeIssue('2026-12-01');                       // due 2026-12-31
    closeIssue('2027-01-01');
    collectAt('2027-01-02');
    (new RunDunning)->execute();
    expect($a->fresh()->status)->toBe(InvoiceStatus::Overdue);

    collectAt('2027-02-01');
    (new CloseBillingPeriods('2027-02-01'))->execute();
    $statement = Statement::where('period_start', '2027-01-01')->sole();
    $fee = $statement->lines->firstWhere('kind.value', 'late_fee');

    // 119000 × 0.1% × 31 days (2027-01-01..2027-01-31)
    expect($fee->amount)->toBe(3689)->and($fee->meta['days'])->toBe(31)
        ->and($statement->subtotal)->toBe(103689)->and($statement->total)->toBe(123390)
        ->and(LateFee::sole()->statement_id)->toBe($statement->id);

    // Second close continues from the last charged day; the cap stops it.
    $this->putJson("/p/billing/clients/{$this->client->id}/terms", ['late_fee_cap_percent' => 4])->assertOk()->assertJsonPath('late_fee_cap_percent', 4);
    (new IssueInvoice(Invoice::where('status', 'draft')->sole(), send: false))->execute();
    collectAt('2027-03-01');
    (new CloseBillingPeriods('2027-03-01'))->execute();
    $feb = LateFee::where('from_date', '2027-02-01')->sole();
    expect($feb->amount)->toBe(4760 - 3689);   // cap 4% of 119000 = 4760

    // Exempt clients and a daily basis.
    $this->putJson("/p/billing/clients/{$this->client->id}/terms", ['late_fee_exempt' => true, 'late_fee_basis' => 'daily'])->assertOk()
        ->assertJsonPath('late_fee_exempt', true)->assertJsonPath('late_fee_basis', 'daily');
    expect(\Innertia\Billing\Support\LateFeeCalculator::forSpace((string) $this->tenant->id, '2027-03-31'))->toBe([])
        ->and(Activity::where('subject_type', 'terms')->count())->toBe(2);
});

it('waives late fees: removed from a draft, credited when already issued, logged', function () {
    $this->client->update(['late_fee_percent' => 3]);
    $a = closeIssue('2026-12-01');
    closeIssue('2027-01-01');
    collectAt('2027-01-02');
    (new RunDunning)->execute();
    collectAt('2027-02-01');
    (new CloseBillingPeriods('2027-02-01'))->execute();
    $draft = Invoice::where('status', 'draft')->sole();
    expect($draft->total)->toBe(123390);

    $this->postJson("/p/billing/invoices/{$a->id}/waive-late-fees", ['reason' => 'Acuerdo comercial'])->assertOk()->assertJsonPath('removed', 3689);
    expect($draft->fresh()->total)->toBe(119000)
        ->and($a->fresh()->late_fees_waived_at)->not->toBeNull()
        ->and($a->fresh()->events->pluck('event')->all())->toContain('late_fee_waived');
    conflict(fn () => $this->postJson("/p/billing/invoices/{$a->id}/waive-late-fees", ['reason' => 'otra vez']));

    // Issued already → credit note on the invoice that carried it.
    $b = Invoice::where('status', 'issued')->where('period_start', '2026-12-01')->sole();
    collectAt('2027-02-02');
    (new RunDunning)->execute();
    collectAt('2027-03-01');
    (new CloseBillingPeriods('2027-03-01'))->execute();   // b overdue in February: 28 days × 0.1% × 119000 = 3332
    $issued = (new IssueInvoice(Invoice::where('status', 'draft')->latest('created_at')->first(), send: false))->execute();
    $result = (new WaiveLateFees($b, 'Error en el plazo'))->execute();
    expect($result['credited'])->toBe(3965)                // 3332 + 19% VAT
        ->and($issued->fresh()->balance)->toBe($issued->total - 3965);
});

it('reissues an invoice: voids it, moves statements and payments, corrects data and lines, logs the differences', function () {
    $a = closeIssue('2026-12-01');
    (new RegisterPayment((string) $this->client->id, 50000, invoiceId: $a->id))->execute();

    $new = $this->postJson("/p/billing/invoices/{$a->id}/reissue", [
        'reason' => 'RUT mal digitado y descuento no aplicado',
        'billing_data' => ['tax_id' => '76.222.222-2'],
        'lines' => [['description' => 'Descuento acordado', 'amount' => -10000]],
        'send' => false,
    ])->assertCreated()->json();

    $old = $a->fresh();
    expect($old->status)->toBe(InvoiceStatus::Void)->and($old->void_reason)->toBe('RUT mal digitado y descuento no aplicado')
        ->and($old->replaced_by_invoice_id)->toBe($new['id'])->and($old->balance)->toBe(0)
        ->and($new['replaces_invoice_id'])->toBe($a->id)
        ->and($new['display_number'])->toBe('F-000002')
        ->and($new['total'])->toBe(107100)               // 90000 + 19%
        ->and($new['balance'])->toBe(57100)              // the 50000 paid moved over
        ->and($new['due_at'])->toBe($a->due_at->toIso8601String())
        ->and($new['billing_data']['tax_id'])->toBe('76.222.222-2')
        ->and($new['billing_data']['legal_name'])->toBe('Cliente SpA');

    $reissued = collect($new['events'])->firstWhere('event', 'reissued');
    expect($reissued['data']['billing_data'])->toBe(['tax_id' => ['before' => '76.111.111-1', 'after' => '76.222.222-2']])
        ->and($reissued['data']['totals']['before']['total'])->toBe(119000)
        ->and($reissued['data']['totals']['after']['total'])->toBe(107100)
        ->and($reissued['data']['reason'])->toBe('RUT mal digitado y descuento no aplicado')
        ->and(collect($old->events)->pluck('event')->all())->toContain('voided');

    conflict(fn () => $this->postJson("/p/billing/invoices/{$a->id}/reissue", ['reason' => 'x']));
    $this->postJson("/p/billing/invoices/{$new['id']}/void", [])->assertUnprocessable();
});

it('returns what exceeds the reissued total and the payments of a voided invoice as client credit', function () {
    $a = closeIssue('2026-12-01');
    $payment = (new RegisterPayment((string) $this->client->id, 119000, invoiceId: $a->id))->execute();

    $new = (new ReissueInvoice($a, 'Precio negociado', lines: [['description' => 'Rebaja', 'amount' => -50000]], send: false))->execute();
    expect($new->total)->toBe(59500)->and($new->status)->toBe(InvoiceStatus::Paid)
        ->and($payment->fresh()->unallocated_amount)->toBe(59500);

    $voided = (new VoidInvoice($new, 'Servicio no prestado'))->execute();
    expect($voided->status)->toBe(InvoiceStatus::Void)->and($payment->fresh()->unallocated_amount)->toBe(119000);
    expect(fn () => (new VoidInvoice($voided, 'x'))->execute())->toThrow(ConflictException::class);
});

it('builds the account statement with running balance and aging, for the client and for the space', function () {
    $a = closeIssue('2026-12-01');
    collectAt('2026-12-05');
    (new RegisterPayment((string) $this->client->id, 50000, reference: 'trf-9'))->execute();
    (new CreateCreditNote((string) $this->client->id, 10000, 'Ajuste', 'invoice', $a->id))->execute();
    $b = closeIssue('2027-01-01');
    collectAt('2027-02-15');

    $account = $this->getJson("/p/billing/clients/{$this->client->id}/account")->assertOk()->json();
    expect(collect($account['movements'])->pluck('type')->all())->toBe(['invoice', 'payment', 'credit_note', 'invoice'])
        ->and($account['closing_balance'])->toBe(119000 * 2 - 60000)
        ->and($account['aging']['d31_60'])->toBe(59000)          // a: due 2026-12-31 → 46 days
        ->and($account['aging']['d0_30'])->toBe(119000)          // b: due 2027-01-31 → 15 days
        ->and($account['aging']['total'])->toBe(178000);

    $ranged = $this->getJson("/p/billing/clients/{$this->client->id}/account?from=2027-01-01")->json();
    expect($ranged['opening_balance'])->toBe(59000)->and($ranged['movements'])->toHaveCount(1)->and($ranged['closing_balance'])->toBe(178000);

    $export = $this->get("/p/billing/clients/{$this->client->id}/account/export")->assertOk();
    expect($export->headers->get('content-type'))->toContain('spreadsheetml');

    Innertia::activate('rancagua');
    $this->getJson('/b/billing/account')->assertOk()->assertJsonPath('closing_balance', 178000)->assertJsonPath('credit_available', null);
});

it('sends payment reminders before, on and after the due date, escalates and never repeats', function () {
    BillingSettings::save(['reminder_days_before' => '7,3', 'reminder_escalation_email' => 'cobranza@plataforma.cl']);
    closeIssue('2026-12-01');                                   // due 2026-12-31

    $run = fn (string $date) => (new SendPaymentReminders($date))->execute();
    expect($run('2026-12-24')['sent'])->toBe(1)                // 7 days before
        ->and($run('2026-12-24')['sent'])->toBe(0)             // idempotent
        ->and($run('2026-12-26')['sent'])->toBe(0)
        ->and($run('2026-12-28')['sent'])->toBe(1)             // 3 days before
        ->and($run('2026-12-31')['sent'])->toBe(1)             // due day
        ->and($run('2027-01-01')['sent'])->toBe(1)             // overdue #1
        ->and($run('2027-01-02')['sent'])->toBe(0)
        ->and($run('2027-01-08')['sent'])->toBe(1)             // overdue #2
        ->and($run('2027-01-15'))->toBe(['sent' => 1, 'escalated' => 1, 'skipped' => 0]);

    expect(Reminder::pluck('kind')->all())->toBe(['before', 'before', 'due', 'overdue', 'overdue', 'overdue']);
    Mail::assertQueued(NotificationMail::class, fn ($m) => $m->hasTo('pagos@cliente.cl') && $m->hasCc('cobranza@plataforma.cl'));

    $this->putJson("/p/billing/clients/{$this->client->id}/terms", ['reminders_enabled' => false])->assertOk();
    expect($run('2027-01-22'))->toBe(['sent' => 0, 'escalated' => 0, 'skipped' => 1]);

    $this->putJson('/p/billing/settings', ['reminders_enabled' => false, 'reminder_overdue_every_days' => 5])->assertOk()
        ->assertJsonPath('reminders_enabled', false)->assertJsonPath('reminder_overdue_every_days', 5)->assertJsonPath('reminder_days_before', '7,3');
    $this->postJson('/p/billing/run/reminders')->assertOk()->assertJsonPath('sent', 0);
});

it('reports revenue, receivables, aging, collections, MRR and usage, and exports them', function () {
    \Innertia\Billing\Meters::record($this->tenant, 'sms.messages', 5, '2026-11-10 10:00:00', sourceRef: 'r1');
    $a = closeIssue('2026-12-01');
    closeIssue('2027-01-01');
    collectAt('2027-01-05');
    (new RegisterPayment((string) $this->client->id, 119000, invoiceId: $a->id))->execute();
    (new CreateCreditNote((string) $this->client->id, 5000, 'Ajuste', 'balance'))->execute();
    collectAt('2027-02-15');

    $this->getJson('/p/billing/reports/revenue?from=2026-12-01&to=2027-01-31')->assertOk()
        ->assertJsonPath('rows.0.key', '2026-12')->assertJsonPath('totals.total', 238000)->assertJsonPath('totals.invoices', 2);
    $this->getJson('/p/billing/reports/revenue?from=2026-12-01&to=2027-01-31&group_by=plan')->assertOk()->assertJsonPath('rows.0.label', 'Pro');
    $this->getJson('/p/billing/reports/revenue?from=2026-12-01&to=2027-01-31&group_by=client')->assertOk()->assertJsonPath('rows.0.label', 'Rancagua');
    $this->getJson('/p/billing/reports/receivables')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.days_overdue', 15);
    $this->getJson('/p/billing/reports/aging')->assertOk()->assertJsonPath('totals.d0_30', 119000);
    $this->getJson('/p/billing/reports/collections?from=2027-01-01&to=2027-01-31')->assertOk()
        ->assertJsonPath('totals.amount', 119000)->assertJsonPath('totals.payments', 1);   // the credit note is not money
    $this->getJson('/p/billing/reports/mrr?group_by=plan')->assertOk()->assertJsonPath('totals.mrr', 100000);
    $this->getJson('/p/billing/reports/usage?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonPath('rows.0.quantity', 5);
    $this->getJson('/p/billing/reports/nope')->assertNotFound();

    $xlsx = $this->get('/p/billing/reports/aging/export')->assertOk();
    expect($xlsx->headers->get('content-type'))->toContain('spreadsheetml')
        ->and(substr($xlsx->getContent(), 0, 2))->toBe('PK');
});

it('keeps writes out of the read-only routes and the backoffice', function () {
    $a = closeIssue('2026-12-01');

    $this->getJson('/r/billing/adjustments')->assertOk();
    $this->getJson("/r/billing/clients/{$this->client->id}/account")->assertOk();
    $this->postJson('/r/billing/adjustments', ['tenant_key' => 'rancagua', 'type' => 'discount', 'amount' => 1, 'reason' => 'x'])->assertStatus(405);
    $this->postJson("/r/billing/invoices/{$a->id}/void", ['reason' => 'x'])->assertNotFound();
    $this->postJson('/r/billing/reconciliation/imports')->assertStatus(405);

    Innertia::activate('rancagua');
    $this->postJson("/b/billing/invoices/{$a->id}/void", ['reason' => 'x'])->assertNotFound();
    $this->postJson('/b/billing/credit-notes', [])->assertNotFound();
});

it('splits the late fee of a consolidated invoice by each space share', function () {
    $this->client->update(['late_fee_percent' => 3, 'invoicing_mode' => 'consolidated']);
    billingTenant('machali', (string) $this->client->id);
    (new StartSubscription('machali', billingPlan(['seat_price' => 0, 'base_price' => 300000])->id, startsAt: '2026-11-01', trialDays: 0))->execute();
    $a = closeIssue('2026-12-01');                       // 119000 + 357000 = 476000
    expect($a->total)->toBe(476000);
    collectAt('2027-01-02');
    (new RunDunning)->execute();

    $rancagua = \Innertia\Billing\Support\LateFeeCalculator::forSpace((string) $this->tenant->id, '2027-01-31');
    $machali = \Innertia\Billing\Support\LateFeeCalculator::forSpace((string) billingTenant('machali')->id, '2027-01-31');
    expect($rancagua[0]['base'])->toBe(119000)->and($rancagua[0]['amount'])->toBe(3689)
        ->and($machali[0]['base'])->toBe(357000)->and($machali[0]['amount'])->toBe(11067);
});
