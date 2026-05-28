<?php

namespace Innertia\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot: user ↔ context access.
 *
 * `context` is a string key matching a key in config('innertia.contexts').
 * No FK to a contexts table — contexts are config-only.
 */
class UserContext extends Model
{
    protected $table = 'user_contexts';

    protected $fillable = ['user_id', 'context', 'tenant_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'user_id');
    }
}
