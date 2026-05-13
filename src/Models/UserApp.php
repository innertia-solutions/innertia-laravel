<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot: user ↔ app access.
 *
 * `app` is a string key matching a key in config('innertia.apps').
 * No FK to an apps table — apps are config-only.
 */
class UserApp extends Model
{
    protected $fillable = ['user_id', 'app', 'tenant_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'user_id');
    }
}
