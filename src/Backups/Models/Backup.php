<?php

namespace Innertia\Backups\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Traits\Auditable;

class Backup extends Model
{
    use HasUuids;
    use Auditable;

    protected $table = 'backups';

    protected $fillable = [
        'status', 'disk', 'path', 'size_bytes', 'checksum',
        'driver', 'mode', 'is_safety', 'error', 'completed_at',
    ];

    protected $casts = [
        'size_bytes'   => 'integer',
        'is_safety'    => 'boolean',
        'completed_at' => 'datetime',
    ];

    protected $attributes = [
        'status'    => 'pending',
        'is_safety' => false,
    ];
}
