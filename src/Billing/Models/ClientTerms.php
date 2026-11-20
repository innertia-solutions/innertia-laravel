<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Collection terms of a client (one row per client, created on demand). The late fee rate itself is
 * clients.late_fee_percent (0 = off); here: its basis (percent per month or per day), a cap over the
 * invoice total, an exemption switch and whether payment reminders are sent.
 */
class ClientTerms extends Model
{
    protected $table = 'billing_client_terms';

    protected $primaryKey = 'client_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['client_id', 'late_fee_basis', 'late_fee_cap_percent', 'late_fee_exempt', 'reminders_enabled', 'updated_by'];

    protected $attributes = ['late_fee_basis' => 'monthly', 'late_fee_exempt' => false, 'reminders_enabled' => true];

    protected $casts = [
        'late_fee_cap_percent' => 'float',
        'late_fee_exempt'      => 'boolean',
        'reminders_enabled'    => 'boolean',
    ];

    /** Stored terms or the defaults (not persisted). */
    public static function for(string $clientId): self
    {
        return self::query()->find($clientId) ?? new self(['client_id' => $clientId]);
    }
}
