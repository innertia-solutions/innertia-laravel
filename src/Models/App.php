<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a product app (portal/context) a user can log into.
 * Apps are static — defined by the product and seeded, not created at runtime.
 *
 * Examples: 'backoffice', 'student', 'technician'
 */
class App extends Model
{
    protected $fillable = ['key', 'name', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function tenantApps(): HasMany
    {
        return $this->hasMany(TenantApp::class);
    }

    public static function findByKey(string $key): ?static
    {
        return static::where('key', $key)->where('active', true)->first();
    }
}
