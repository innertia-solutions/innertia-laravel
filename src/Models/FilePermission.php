<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FilePermission extends Model
{
    use HasUuids;

    protected $fillable = [
        'file_id',
        'permissionable_type',
        'permissionable_id',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
