<?php

namespace Innertia\Auth\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Innertia\Auth\RBAC\Traits\HasApps;
use Innertia\Auth\RBAC\Traits\HasRoles;
use Innertia\Platform\Traits\Auditable;
use Innertia\Platform\Traits\HasHistory;
use Innertia\Platform\Traits\HasPreferences;
use Innertia\Platform\Traits\HasUuid;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Base User model.
 *
 * Extend this in your app: class User extends \Innertia\Auth\Models\User { ... }
 *
 * Includes:
 * - JWT authentication (tymon/jwt-auth)
 * - Roles & permissions (innertia RBAC — HasRoles + HasApps)
 * - UUID as primary key
 * - Audit trail + entity history (innertia-laravel)
 * - Soft deletes
 */
abstract class User extends Authenticatable implements JWTSubject
{
    use Auditable, HasApps, HasFactory, HasHistory, HasPreferences, HasUuid, HasRoles, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'seen_at',
        'force_password_change',
        'two_factor_secret',
        'two_factor_enabled',
        'created_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected $casts = [
        'seen_at'               => 'datetime',
        'email_verified_at'     => 'datetime',
        'force_password_change' => 'boolean',
        'two_factor_enabled'    => 'boolean',
    ];

    /* ── Relations ── */

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(static::class, 'created_by');
    }

    /* ── JWTSubject ── */

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
