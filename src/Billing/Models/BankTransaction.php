<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Innertia\Platform\Traits\HasUuid;
use Innertia\Saas\Models\Client;

/**
 * An incoming movement (credit) of a bank statement. Unique by row hash (date, amount, text, reference
 * and occurrence in the file), so overlapping statements never duplicate a movement.
 * status: unmatched | suggested (suggestions with score) | matched (payment_id) | ignored.
 */
class BankTransaction extends Model
{
    use HasUuid;

    public const UNMATCHED = 'unmatched';
    public const SUGGESTED = 'suggested';
    public const MATCHED = 'matched';
    public const IGNORED = 'ignored';

    protected $table = 'billing_bank_transactions';

    protected $fillable = [
        'import_id', 'row_hash', 'posted_at', 'amount', 'description', 'reference', 'tax_id', 'counterpart_name',
        'raw', 'status', 'score', 'suggestions', 'client_id', 'payment_id', 'matched_by', 'matched_at',
        'ignore_reason', 'trace_id',
    ];

    protected $casts = [
        'posted_at'   => \Innertia\Billing\Support\LocalDate::class,
        'amount'      => 'integer',
        'raw'         => 'array',
        'score'       => 'integer',
        'suggestions' => 'array',
        'matched_at'  => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::UNMATCHED, self::SUGGESTED], true);
    }
}
