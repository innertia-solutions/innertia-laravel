<?php

namespace Innertia\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Billing\Enums\LineKind;
use Innertia\Platform\Traits\HasUuid;

class StatementLine extends Model
{
    use HasUuid;

    protected $table = 'billing_statement_lines';

    protected $fillable = ['statement_id', 'kind', 'description', 'meter', 'quantity', 'unit_price', 'amount', 'meta', 'position'];

    protected $casts = [
        'kind'       => LineKind::class,
        'quantity'   => 'float',
        'unit_price' => 'integer',
        'amount'     => 'integer',
        'meta'       => 'array',
        'position'   => 'integer',
    ];
}
