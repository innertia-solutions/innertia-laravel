<?php

namespace Innertia\Platform\Traits;

use Innertia\Facades\Innertia;

/**
 * Sister trait of HasTenant. Opt-in second-level scoping.
 *
 *   - When config('innertia.organizations.enabled') is false → no-op.
 *   - On `creating`: if Innertia::organization()->current() is set and the
 *     attribute is not yet assigned, it is injected.
 *   - addGlobalScope('organization'): WHERE organization_id IN (scope()).
 *     When scope() is empty (CLI / job / no context), the scope is NOT applied
 *     so background workers can iterate across organizations.
 *
 * Apply to any model whose table carries `organization_id`. The column itself
 * is created by the `innertia:organization:install` artisan command.
 *
 * Note: the `enabled` config check is performed at runtime inside the
 * callbacks (not at boot time) because Eloquent boots traits once per class
 * lifetime in the PHP process. Performing the check at boot would freeze the
 * trait into whatever state the config had at first boot, causing surprising
 * behaviour when callers toggle the flag (e.g. tests, or runtime feature
 * gates).
 */
trait HasOrganization
{
    public static function bootHasOrganization(): void
    {
        $column = config('innertia.organizations.column', 'organization_id');

        static::creating(function ($model) use ($column) {
            if (! config('innertia.organizations.enabled')) {
                return;
            }
            $ctx = Innertia::organization();
            if ($ctx === null) {
                return;
            }
            if (empty($model->{$column}) && $ctx->current() !== null) {
                $model->{$column} = $ctx->current();
            }
        });

        static::addGlobalScope('organization', function ($query) use ($column) {
            if (! config('innertia.organizations.enabled')) {
                return;
            }
            $ctx = Innertia::organization();
            if ($ctx === null) {
                return;
            }
            $scope = $ctx->scope();
            if (empty($scope)) {
                return; // No context → no scoping (CLI/jobs).
            }
            $query->whereIn(
                $query->getModel()->getTable() . '.' . $column,
                $scope
            );
        });
    }
}
