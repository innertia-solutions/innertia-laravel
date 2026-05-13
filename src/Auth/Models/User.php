<?php

namespace Innertia\Auth\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Innertia\Platform\Traits\Auditable;
use Innertia\Auth\RBAC\Traits\HasApps;
use Innertia\Platform\Traits\HasHistory;
use Innertia\Platform\Traits\HasUuid;
use Spatie\Permission\Traits\HasPermissions;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Base User model.
 *
 * Extend this in your app: class User extends \Innertia\Auth\Models\User { ... }
 *
 * Includes:
 * - JWT authentication (tymon/jwt-auth)
 * - Roles & permissions (spatie/laravel-permission)
 * - NanoId as primary key
 * - Audit trail + entity history (innertia-laravel)
 * - Soft deletes
 */
abstract class User extends Authenticatable implements JWTSubject
{
    use Auditable, HasApps, HasFactory, HasHistory, HasUuid, HasPermissions, HasRoles, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'seen_at',
        'force_password_change',
        'two_factor_secret',
        'two_factor_enabled',
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
