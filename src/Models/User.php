<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Innertia\Traits\Auditable;
use Innertia\Traits\HasHistory;
use Innertia\Traits\HasNanoId;
use Spatie\Permission\Traits\HasPermissions;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Base User model.
 *
 * Extend this in your app: class User extends \Innertia\Models\User { ... }
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
    use Auditable, HasFactory, HasHistory, HasNanoId, HasPermissions, HasRoles, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'seen_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'seen_at'    => 'datetime',
        'email_verified_at' => 'datetime',
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
