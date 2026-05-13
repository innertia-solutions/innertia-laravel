<?php

namespace Innertia\Platform\Traits;

use Innertia\InnertiaMode;

trait HasTenant
{
    public static function bootHasTenant(): void
    {
        if (config('innertia.mode') !== 'saas') {
            return; // no-op in single-tenant mode
        }

        static::creating(function ($model) {
            if (empty($model->tenant_id) && function_exists('tenant') && tenant()) {
                $model->tenant_id = (string) tenant('id');
            }
        });

        static::addGlobalScope('tenant', function ($query) {
            if (function_exists('tenant') && tenant()) {
                $query->where($query->getModel()->getTable() . '.tenant_id', tenant('id'));
            }
        });
    }
}
