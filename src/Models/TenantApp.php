<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantApp extends Model
{
    protected $fillable = ['tenant_id', 'app_id', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
