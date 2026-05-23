<?php

namespace Innertia\Platform\Contracts;

/**
 * Optional contract for Organization models.
 *
 * The library ships a concrete base model
 * (`Innertia\Platform\Organizations\Models\Organization`) which already
 * implements this interface. The vast majority of apps will simply extend
 * that base class.
 *
 * This contract is exposed for apps that prefer to type by interface (e.g.
 * dependency injection, or apps that want to replace the model wholesale
 * rather than extend it). The middleware + OrganizationContext only depend
 * on this surface, never on the concrete class.
 */
interface OrganizationContract
{
    /**
     * The numeric primary key (typically bigint). Used as the value stored
     * in every `organization_id` column.
     */
    public function getKey();

    /**
     * The slug used externally (header `X-Organization: <slug>`).
     */
    public function getRouteKey();

    /**
     * Tenant this organization belongs to. NULL is allowed only in non-saas mode.
     */
    public function getTenantId(): int|string|null;

    /**
     * Resolve an organization by its public slug. Returns null when not found.
     *
     * @return static|null
     */
    public static function findByKey(string $key);
}
