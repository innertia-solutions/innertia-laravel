<?php

namespace Innertia\Api\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Innertia\Platform\Traits\HasUuid;

class Client extends Model
{
    use HasUuid;

    protected $fillable = [
        'product',
        'tenant',
        'name',
        'status',
        'tags',
        'options',
    ];

    protected $casts = [
        'tags'    => 'array',
        'options' => 'array',
    ];

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ClientApiKey::class);
    }

    public function activeApiKeys(): HasMany
    {
        return $this->hasMany(ClientApiKey::class)->active();
    }

    public function isActive(): bool    { return $this->status === 'active'; }
    public function isSuspended(): bool { return $this->status === 'suspended'; }

    public function suspend(): void   { $this->update(['status' => 'suspended']); }
    public function reactivate(): void { $this->update(['status' => 'active']); }

    public static function findByProductTenant(string $product, string $tenant): ?static
    {
        return static::where('product', $product)->where('tenant', $tenant)->first();
    }
}
