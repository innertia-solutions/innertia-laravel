<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserApp extends Model
{
    protected $fillable = ['user_id', 'app_id'];

    public function getFillable(): array
    {
        $fillable = parent::getFillable();

        if (config('innertia.mode') === 'saas') {
            $fillable[] = 'tenant_id';
        }

        return $fillable;
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }
}
