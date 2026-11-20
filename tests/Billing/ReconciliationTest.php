<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\BankTransaction;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\Routes;
use Innertia\Billing\Support\BankValues;
use Innertia\Billing\UseCases\CloseBillingPeriods;
use Innertia\Billing\UseCases\IssueInvoice;
use Innertia\Billing\UseCases\StartSubscription;

beforeEach(function () {
    bootBillingMode($this->app);
    Mail::fake();
    billingSeats(0);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'America/Santiago'));
    $this->tenant = billingTenant();
    $this->client = $this->tenant->client;
    $this->client->update(['billing_email' => 'pagos@cliente.cl', 'legal_name' => 'Cliente SpA', 'tax_id' => '76.111.111-1',
        'payment_terms_days' => 30, 'invoicing_mode' => 'per_space']);
    $plan = billingPlan(['seat_price' => 0, 'base_price' => 100000]);
    (new StartSubscription('rancagua', $plan->id, startsAt: '2026-11-01', trialDays: 0))->execute();

    // Another client with an open invoice of a different amount (noise for the matcher).
    $this->otherTenant = billingTenant('ajeno');
    $this->otherTenant->client->update(['name' => 'Ajeno', 'tax_id' => '99.888.777-6', 'invoicing_mode' => 'per_space']);
    (new StartSubscription('ajeno', billingPlan(['seat_price' => 0, 'base_price' => 50000])->id, startsAt: '2026-11-01', trialDays: 0))->execute();

    $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00:00', 'America/Santiago'));
    (new CloseBillingPeriods('2026-12-01'))->execute();
    foreach (Invoice::where('status', 'draft')->orderBy('total', 'desc')->get() as $draft) {
        (new IssueInvoice($draft, send: false))->execute();
    }
    $this->a = Invoice::where('client_id', $this->client->id)->sole();      // F-000001 · 119000
    $this->travelTo(CarbonImmutable::parse('2027-01-01 09:00:00', 'America/Santiago'));
    (new CloseBillingPeriods('2027-01-01'))->execute();
    $this->b = (new IssueInvoice(Invoice::where('status', 'draft')->where('client_id', $this->client->id)->sole(), send: false))->execute(); // F-000003
    $this->travelTo(CarbonImmutable::parse('2027-01-10 09:00:00', 'America/Santiago'));

    Route::prefix('p')->group(function () {
        Routes::platform();
        Routes::platformWrite();
    });
});

function cartola(array $lines): string
{
    return implode("\r\n", array_merge([
        'Banco de Prueba S.A.',
        'Cartola histórica;Cuenta 123-456',
        'Fecha;Descripción;N° Documento;RUT Origen;Cargos;Abonos;Saldo',
    ], $lines)) . "\r\n";
}

const CARTOLA_MAPPING = ['date' => 'Fecha', 'description' => 'Descripción', 'reference' => 'N° Documento', 'tax_id' => 'RUT Origen', 'credit' => 'Abonos', 'debit' => 'Cargos'];

function uploadCartola(string $content, string $name = 'cartola.csv', array $mapping = CARTOLA_MAPPING)
{
    return test()->post('/p/billing/reconciliation/imports', [
        'file' => UploadedFile::fake()->createWithContent($name, $content),
        'mapping' => json_encode($mapping),
        'bank' => 'Banco de Prueba',
    ], ['Accept' => 'application/json']);
}

it('parses Chilean amounts, dates and RUTs', function () {
    expect(BankValues::amount('1.234.567'))->toBe(1234567)
        ->and(BankValues::amount('$ 119.000'))->toBe(119000)
        ->and(BankValues::amount('1,234,567'))->toBe(1234567)
        ->and(BankValues::amount('1.234,50'))->toBe(1235)
        ->and(BankValues::amount('(5.000)'))->toBe(-5000)
        ->and(BankValues::amount(''))->toBeNull()
        ->and(BankValues::date('02/12/2026'))->toBe('2026-12-02')
        ->and(BankValues::date('2026-12-02 10:00'))->toBe('2026-12-02')
        ->and(BankValues::date('3-1-27'))->toBe('2027-01-03')
        ->and(BankValues::date(46358))->toBe('2026-12-02')
        ->and(BankValues::date('31/02/2026'))->toBeNull()
        ->and(BankValues::rutsIn('Transf de 76.111.111-1 pago'))->toBe(['761111111']);
});

it('previews a cartola: finds the header row under the bank title and suggests the mapping', function () {
    $this->post('/p/billing/reconciliation/preview', [
        'file' => UploadedFile::fake()->createWithContent('cartola.csv', mb_convert_encoding(cartola(['02/12/2026;Transf;111;;;119.000;1']), 'Windows-1252', 'UTF-8')),
    ], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('header_row', 3)
        ->assertJsonPath('mapping.date', 'Fecha')
        ->assertJsonPath('mapping.credit', 'Abonos')
        ->assertJsonPath('mapping.debit', 'Cargos')
        ->assertJsonPath('mapping.description', 'Descripción')
        ->assertJsonPath('mapping.tax_id', 'RUT Origen')
        ->assertJsonPath('rows.0.5', '119.000');
});

it('imports a cartola once, applies conclusive matches alone and leaves the rest pending', function () {
    $csv = cartola([
        '12/01/2027;Transferencia de Cliente SpA;111;76.111.111-1;;119.000;500.000',  // RUT + exact amount → F-000001
        '12/01/2027;Pago proveedor;222;;50.000;;450.000',                              // debit: skipped
        '13/01/2027;Transf. pago F-000003;333;;;119.000;569.000',                      // reference + amount → F-000003
        '14/01/2027;Deposito sin datos;444;;;7.000;576.000',                           // nothing → unmatched
    ]);

    $import = uploadCartola($csv)->assertCreated()->assertJsonPath('duplicate', false)->json();
    $this->getJson("/p/billing/reconciliation/imports/{$import['id']}")->assertOk()
        ->assertJsonPath('status', 'done')->assertJsonPath('rows_imported', 3)->assertJsonPath('rows_skipped', 1)
        ->assertJsonPath('matched', 2)->assertJsonPath('suggested', 0);

    expect($this->a->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->b->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(BankTransaction::where('status', 'matched')->pluck('matched_by')->unique()->all())->toBe(['auto'])
        ->and(Payment::whereNotNull('bank_transaction_id')->count())->toBe(2)
        ->and(Activity::where('action', 'auto_matched')->count())->toBe(2);

    // Same file again: same import, nothing duplicated.
    uploadCartola($csv)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('id', $import['id']);
    // Overlapping statement: the shared movement is recognized, only the new one is added.
    $next = uploadCartola(cartola([
        '14/01/2027;Deposito sin datos;444;;;7.000;576.000',
        '15/01/2027;Otro deposito;555;;;8.000;584.000',
    ]), 'cartola-2.csv')->assertCreated()->json('id');
    $this->getJson("/p/billing/reconciliation/imports/{$next}")->assertJsonPath('rows_imported', 1)->assertJsonPath('rows_duplicated', 1);

    expect(BankTransaction::count())->toBe(4);
    $this->getJson('/p/billing/reconciliation/transactions?status=open')->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('summary.open_amount', 15000);
});

it('suggests, matches by hand over several invoices with partial payment and credit, undoes and ignores', function () {
    uploadCartola(cartola([
        '12/01/2027;Abono cliente 76.111.111-1;900;;;200.000;1',
        '12/01/2027;Intereses;901;;;350;1',
    ]))->assertCreated();

    $t = BankTransaction::where('amount', 200000)->sole();
    expect($t->status)->toBe('suggested')
        ->and($t->suggestions[0]['client_id'])->toBe((string) $this->client->id)
        ->and($t->suggestions[0]['reasons'])->toContain('tax_id_in_text')
        ->and($t->score)->toBeLessThan(85);

    // One movement, two invoices: the first is paid, the second partially.
    $this->postJson("/p/billing/reconciliation/transactions/{$t->id}/match", ['client_id' => $this->client->id, 'invoice_ids' => [$this->a->id, $this->b->id]])
        ->assertOk()->assertJsonPath('status', 'matched');
    expect($this->a->fresh()->status)->toBe(InvoiceStatus::Paid)->and($this->b->fresh()->balance)->toBe(38000);
    expect(fn () => $this->withoutExceptionHandling()->postJson("/p/billing/reconciliation/transactions/{$t->id}/match", ['client_id' => $this->client->id]))
        ->toThrow(\Innertia\Exceptions\ConflictException::class);
    $this->withExceptionHandling();

    // Undo: invoices reopen, the payment disappears.
    $this->postJson("/p/billing/reconciliation/transactions/{$t->id}/unmatch", ['reason' => 'Cliente equivocado'])->assertOk()->assertJsonPath('status', 'suggested');
    expect($this->a->fresh()->balance)->toBe(119000)->and($this->b->fresh()->balance)->toBe(119000)
        ->and(Payment::count())->toBe(0);

    // Only one invoice: the rest stays as client credit.
    $this->postJson("/p/billing/reconciliation/transactions/{$t->id}/match", ['client_id' => $this->client->id, 'invoice_ids' => [$this->a->id]])->assertOk();
    expect(Payment::sole()->unallocated_amount)->toBe(81000);
    $this->postJson("/p/billing/clients/{$this->client->id}/apply-credit", ['invoice_id' => $this->b->id])->assertOk()
        ->assertJsonPath('applied', 81000)->assertJsonPath('credit_available', 0);
    expect($this->b->fresh()->balance)->toBe(38000);

    $interest = BankTransaction::where('amount', 350)->sole();
    $this->postJson("/p/billing/reconciliation/transactions/{$interest->id}/ignore", ['reason' => 'Intereses del banco'])->assertOk()->assertJsonPath('status', 'ignored');
    $this->postJson("/p/billing/reconciliation/transactions/{$interest->id}/restore")->assertOk()->assertJsonPath('status', 'unmatched');

    $this->getJson("/p/billing/reconciliation/open-invoices?client_id={$this->client->id}")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/p/billing/activity?client_id={$this->client->id}")->assertOk()->assertJsonFragment(['subject_type' => 'credit', 'action' => 'applied']);
});

it('imports an xlsx cartola with a single amount column', function () {
    $path = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
    $writer = new \OpenSpout\Writer\XLSX\Writer;
    $writer->openToFile($path);
    $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Movimientos']));
    $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Fecha', 'Glosa', 'Monto', 'RUT']));
    $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['12-01-2027', 'TEF Cliente SpA', 119000, '761111111']));
    $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['13-01-2027', 'Comision', -1500, '']));
    $writer->close();

    uploadCartola((string) file_get_contents($path), 'banco.xlsx', ['date' => 'Fecha', 'description' => 'Glosa', 'amount' => 'Monto', 'tax_id' => 'RUT'])
        ->assertCreated()->assertJsonPath('format', 'xlsx');
    @unlink($path);

    expect(BankTransaction::sole()->status)->toBe('matched')->and($this->a->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('fails clearly when the mapped columns are not in the file and can be retried', function () {
    $response = uploadCartola(cartola(['12/01/2027;x;1;;;1.000;1']), 'mala.csv', ['date' => 'Fecha', 'amount' => 'Importe'])->assertCreated();
    $this->getJson("/p/billing/reconciliation/imports/{$response->json('id')}")->assertJsonPath('status', 'failed');

    uploadCartola(cartola(['12/01/2027;x;1;;;1.000;1']), 'mala.csv')->assertCreated()->assertJsonPath('id', $response->json('id'));
    $this->getJson("/p/billing/reconciliation/imports/{$response->json('id')}")->assertJsonPath('status', 'done');
    $this->post('/p/billing/reconciliation/imports', ['file' => UploadedFile::fake()->createWithContent('x.pdf', 'x'), 'mapping' => ['date' => 'F', 'amount' => 'M']], ['Accept' => 'application/json'])
        ->assertUnprocessable();
});
