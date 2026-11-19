<?php

namespace Innertia\Notifications;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationsFeature;

/**
 * Generic recipient resolution (no hardcoded roles like 'super-admin').
 *
 *   Recipients::withPermission('phone.manage');                 // members of the active tenant with the permission
 *   Recipients::withPermission('messages.send', organizationId: $sedeId);
 *   Recipients::withRole('coordinator');
 *   Recipients::users([$user, $otherId]);
 *   Recipients::platformTeam();                                 // platform owner + staff
 *
 * Permissions/roles are evaluated in the ACTIVE tenant (resolve recipients where the event
 * happens, before the Notifier queues the delivery).
 */
final class Recipients
{
    public static function userModel(): string
    {
        return (string) config('innertia.auth.user_model', config('auth.providers.users.model', \App\Models\User::class));
    }

    /** Users by model or id (nulls ignored). */
    public static function users(iterable $users): Collection
    {
        $ids = Notice::normalizeRecipients($users);
        $model = self::userModel();

        return $ids === [] ? collect() : $model::query()->whereIn((new $model)->getKeyName(), $ids)->get();
    }

    public static function withPermission(string|\BackedEnum $permission, ?string $organizationId = null): Collection
    {
        return self::members($organizationId)
            ->filter(fn ($user) => method_exists($user, 'hasPermission') && $user->hasPermission($permission))
            ->values();
    }

    public static function withRole(string $role, ?string $organizationId = null): Collection
    {
        return self::members($organizationId)
            ->filter(fn ($user) => method_exists($user, 'hasRole') && $user->hasRole($role, $organizationId !== null && is_numeric($organizationId) ? (int) $organizationId : null))
            ->values();
    }

    /** Platform team (is_platform_admin + platform_role in $roles). Empty when the columns do not exist. */
    public static function platformTeam(array $roles = ['owner', 'staff']): Collection
    {
        $model = self::userModel();
        $table = (new $model)->getTable();

        if (! Schema::hasColumn($table, 'is_platform_admin')) {
            return collect();
        }

        return $model::query()
            ->where('is_platform_admin', true)
            ->when(Schema::hasColumn($table, 'platform_role'), fn ($q) => $q->whereIn('platform_role', $roles))
            ->get();
    }

    /**
     * Members of the active tenant (any context row), optionally restricted to an organization
     * (rows for that organization or for all of them). App mode: every user.
     */
    public static function members(?string $organizationId = null): Collection
    {
        $model = self::userModel();
        $instance = new $model;

        if (! Innertia::tenancyEnabled()) {
            return $model::query()->get();
        }

        $tenantId = Innertia::tenant()?->getKey();
        if ($tenantId === null || ! Schema::hasTable('user_contexts')) {
            return collect();
        }

        $ids = DB::table('user_contexts')
            ->where('tenant_id', (string) $tenantId)
            ->when(
                $organizationId !== null && OrganizationsFeature::isActive() && Schema::hasColumn('user_contexts', 'organization_id'),
                fn ($q) => $q->where(fn ($w) => $w->where('organization_id', $organizationId)->orWhereNull('organization_id'))
            )
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        return $ids === [] ? collect() : $model::query()->whereIn($instance->getKeyName(), $ids)->get();
    }
}
