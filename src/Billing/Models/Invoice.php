<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Enums\PaymentMethod;
use Innertia\Platform\Traits\HasUuid;
use Innertia\Saas\Models\Client;

/** Invoice of a space (per_space) or of a whole client (consolidated: tenant_id null). */
class Invoice extends Model
{
    use HasUuid;

    protected $table = 'billing_invoices';

    protected $fillable = [
        'client_id', 'tenant_id', 'number', 'period_start', 'period_end', 'currency', 'billing_data', 'subtotal',
        'tax', 'total', 'balance', 'status', 'payment_method', 'folio', 'pdf_file_id', 'payment_link', 'charge_id',
        'issued_at', 'due_at', 'paid_at', 'sent_at', 'replaces_invoice_id', 'void_reason', 'trace_id',
        'voided_at', 'voided_by', 'replaced_by_invoice_id', 'late_fees_waived_at',
    ];

    protected $casts = [
        'number'         => 'integer',
        'period_start'   => \Innertia\Billing\Support\LocalDate::class,
        'period_end'     => \Innertia\Billing\Support\LocalDate::class,
        'billing_data'   => 'array',
        'subtotal'       => 'integer',
        'tax'            => 'integer',
        'total'          => 'integer',
        'balance'        => 'integer',
        'status'         => InvoiceStatus::class,
        'payment_method' => PaymentMethod::class,
        'issued_at'      => 'datetime',
        'due_at'         => 'datetime',
        'paid_at'        => 'datetime',
        'sent_at'        => 'datetime',
        'voided_at'      => 'datetime',
        'late_fees_waived_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class, 'tenant_id');
    }

    public function statements(): HasMany
    {
        return $this->hasMany(Statement::class, 'invoice_id')->orderBy('period_start');
    }

    public function events(): HasMany
    {
        return $this->hasMany(InvoiceEvent::class, 'invoice_id')->orderBy('created_at');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class, 'invoice_id');
    }

    /** Amount already settled (payments and credit notes allocated to it). */
    public function allocatedAmount(): int
    {
        return (int) Allocation::query()->where('invoice_id', $this->id)->sum('amount');
    }

    /** Due date as a local date (billing time zone). */
    public function dueDate(): ?\Carbon\CarbonImmutable
    {
        return $this->due_at ? \Innertia\Billing\Support\BillingClock::date($this->due_at->copy()->timezone(\Innertia\Billing\Support\BillingClock::timezone())) : null;
    }

    public function isConsolidated(): bool
    {
        return $this->tenant_id === null;
    }

    /** Display number: F-000123 (prefix config innertia.billing.number_prefix). */
    public function displayNumber(): ?string
    {
        return $this->number === null ? null
            : config('innertia.billing.number_prefix', 'F-') . str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    /** Records a bitácora entry (billing_invoice_events). */
    public function log(string $event, array $data = [], ?string $userId = null): InvoiceEvent
    {
        return InvoiceEvent::create([
            'invoice_id' => $this->id,
            'event'      => $event,
            'user_id'    => $userId ?? (auth()->check() ? (string) auth()->id() : null),
            'data'       => $data ?: null,
            'trace_id'   => traceId() ?: null,
            'created_at' => now(),
        ]);
    }
}
