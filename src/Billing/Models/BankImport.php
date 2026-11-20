<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Innertia\Platform\Traits\HasUuid;

/** An imported bank statement (cartola). Unique by file hash: importing the same file twice is a no-op. */
class BankImport extends Model
{
    use HasUuid;

    protected $table = 'billing_bank_imports';

    protected $fillable = [
        'file_name', 'file_hash', 'format', 'bank', 'mapping', 'options', 'content', 'status', 'rows_total',
        'rows_imported', 'rows_duplicated', 'rows_skipped', 'matched', 'suggested', 'error', 'created_by',
        'trace_id', 'processed_at',
    ];

    protected $hidden = ['content'];

    protected $casts = [
        'mapping'         => 'array',
        'options'         => 'array',
        'rows_total'      => 'integer',
        'rows_imported'   => 'integer',
        'rows_duplicated' => 'integer',
        'rows_skipped'    => 'integer',
        'matched'         => 'integer',
        'suggested'       => 'integer',
        'processed_at'    => 'datetime',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'import_id');
    }
}
