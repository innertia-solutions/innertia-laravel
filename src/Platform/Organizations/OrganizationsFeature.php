<?php

namespace Innertia\Platform\Organizations;

/**
 * Single source of truth for whether the Organizations feature is active.
 *
 * The feature is active only when:
 *   - config('innertia.organizations.enabled') === true, AND
 *   - config('innertia.mode') !== 'api'  (API mode never participates in
 *     tenant or organization scoping — API consumers handle their own)
 *
 * Use this everywhere instead of reading the raw config. Centralising the
 * decision makes future rule changes a one-line edit.
 */
final class OrganizationsFeature
{
    /**
     * True when the Organizations feature should be active for this process.
     */
    public static function isActive(): bool
    {
        if (! config('innertia.organizations.enabled', false)) {
            return false;
        }

        return config('innertia.mode') !== 'api';
    }
}
