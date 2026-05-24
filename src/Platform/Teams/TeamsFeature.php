<?php

namespace Innertia\Platform\Teams;

/**
 * Single source of truth for whether the Teams feature is active.
 *
 * Active when:
 *   - config('innertia.teams.enabled') === true, AND
 *   - config('innertia.mode') !== 'api'
 *
 * Independent of Organizations. Teams can exist tenant-wide (organization_id NULL)
 * o org-scoped cuando Organizations también está activo.
 */
final class TeamsFeature
{
    public static function isActive(): bool
    {
        if (! config('innertia.teams.enabled', false)) {
            return false;
        }

        return config('innertia.mode') !== 'api';
    }
}
