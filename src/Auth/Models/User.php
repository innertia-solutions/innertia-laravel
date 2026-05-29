<?php

namespace Innertia\Auth\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Innertia\Auth\RBAC\Traits\HasContexts;
use Innertia\Auth\RBAC\Traits\HasRoles;
use Innertia\Platform\Teams\Traits\HasTeams;
use Innertia\Platform\Traits\Auditable;
use Innertia\Platform\Traits\HasHistory;
use Innertia\Platform\Traits\HasPreferences;
use Innertia\Platform\Traits\HasUuid;

/**
 * Base User model.
 *
 * Extend this in your app: class User extends \Innertia\Auth\Models\User { ... }
 *
 * Includes:
 * - JWT authentication (codec propio — firebase/php-jwt vía JwtService)
 * - Roles & permissions (innertia RBAC — HasRoles + HasContexts)
 * - UUID as primary key
 * - Audit trail + entity history (innertia-laravel)
 * - Soft deletes
 * - Teams membership (HasTeams — auto no-op cuando el feature está disabled
 *   via TeamsFeature::isActive()). Users son tenant-level; el contexto de
 *   organization se mediza vía user_contexts (HasContexts), no via HasOrganization.
 */
abstract class User extends Authenticatable
{
    use Auditable, HasContexts, HasFactory, HasHistory, HasPreferences, HasRoles, HasTeams, HasUuid, SoftDeletes;

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
}
