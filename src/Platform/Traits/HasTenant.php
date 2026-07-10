<?php

namespace Innertia\Platform\Traits;

use Innertia\Facades\Innertia;

trait HasTenant
{
    public static function bootHasTenant(): void
    {
        if (! Innertia::tenancyEnabled()) {
            return; // no-op en modo single-tenant (app/api); activo en saas y open
        }

        static::creating(function ($model) {
            if (empty($model->tenant_id) && Innertia::tenant()) {
                $model->tenant_id = Innertia::tenant()->getKey();
            }
        });

        static::addGlobalScope('tenant', function ($query) {
            if (Innertia::tenant()) {
                $query->where(
                    $query->getModel()->getTable() . '.tenant_id',
                    Innertia::tenant()->getKey()
                );
            }
        });
    }
}
