<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Auth\UseCases\Login;
use Innertia\Auth\UseCases\Logout;
use Innertia\Auth\UseCases\RefreshToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'context'  => 'required|string',
        ]);

        $result = (new Login(
            email:    $data['email'],
            password: $data['password'],
            context:  $data['context'],
        ))->execute();

        return response()->json($result);
    }

    /**
     * GET /auth/me
     *
     * Devuelve la identidad completa del usuario autenticado.
     *
     * Shape:
     *   {
     *     user:          { id, name, email, ... },
     *     permissions:   ['users.view', ...],    // direct + via roles + via teams (si features activos)
     *     contexts:      ['backoffice', 'technician'],
     *     organizations: { backoffice: [...], technician: [...] }  // solo si OrganizationsFeature activo
     *     preferences:   { ... }                  // subset público para boot rápido
     *   }
     *
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $userData = $user->toArray();

        // Permissions consolidadas — direct + via roles + via teams (si feature activo)
        $permissions = [];
        if (method_exists($user, 'roles')) {
            $viaRoles = $user->roles()
                ->with('permissions')
                ->get()
                ->flatMap(fn ($role) => $role->permissions->pluck('name'));

            $direct = method_exists($user, 'directPermissions')
                ? $user->directPermissions()->pluck('name')
                : collect();

            $viaTeams = method_exists($user, 'permissionsViaTeams') && \Innertia\Platform\Teams\TeamsFeature::isActive()
                ? collect($user->permissionsViaTeams())
                : collect();

            $permissions = $viaRoles->merge($direct)->merge($viaTeams)->unique()->values()->all();
        }

        // Contexts (context keys del user)
        $contexts = method_exists($user, 'contextKeys') ? $user->contextKeys() : [];

        // Organizations por contexto (solo si feature activo)
        $organizations = null;
        if (\Innertia\Platform\Organizations\OrganizationsFeature::isActive()
            && method_exists($user, 'accessibleOrganizationsByContext')) {
            $organizations = $this->buildOrganizationsByContext($user);
        }

        // Public preferences (appearance, language, etc.)
        $preferences = method_exists($user, 'preferences')
            ? $user->preferences()->onlyPublic()->toArray()
            : [];

        $payload = [
            'user'        => $userData,
            'permissions' => $permissions,
            'contexts'    => $contexts,
            'preferences' => $preferences,
        ];

        if ($organizations !== null) {
            $payload['organizations'] = $organizations;
        }

        // Current organization resolved from X-Organization header (si feature activo)
        if (\Innertia\Platform\Organizations\OrganizationsFeature::isActive()) {
            $currentId = \Innertia\Facades\Innertia::organization()?->current();
            if ($currentId !== null) {
                $orgModel = config('innertia.organizations.model', \Innertia\Platform\Organizations\Models\Organization::class);
                $payload['current_organization'] = $orgModel::query()
                    ->select(['id', 'key', 'name', 'active'])
                    ->find($currentId);
            }
        }

        // Teams del user (si feature activo)
        if (\Innertia\Platform\Teams\TeamsFeature::isActive() && method_exists($user, 'teams')) {
            $payload['teams'] = $user->teams()
                ->select(['teams.id', 'teams.name', 'teams.parent_team_id', 'teams.organization_id'])
                ->get()
                ->map(fn ($t) => [
                    'id'              => $t->id,
                    'name'            => $t->name,
                    'parent_team_id'  => $t->parent_team_id,
                    'organization_id' => $t->organization_id,
                    'role_in_team'    => $t->pivot->role_in_team ?? 'member',
                ]);
        }

        return response()->json($payload);
    }

    /**
     * Transforma `accessibleOrganizationsByContext(): array<context, array<orgId|null>>` en
     * shape para el frontend: `{ context: [{ id, key, name }, ...] }`.
     */
    private function buildOrganizationsByContext($user): array
    {
        $byContext = $user->accessibleOrganizationsByContext();
        if (empty($byContext)) return [];

        $allOrgIds = collect($byContext)->flatten()->filter(fn ($v) => $v !== null)->unique()->values();

        $orgModel = config('innertia.organizations.model', \Innertia\Platform\Organizations\Models\Organization::class);
        $orgs = $orgModel::whereIn('id', $allOrgIds)->get(['id', 'key', 'name'])->keyBy('id');

        $hasNullFallback = collect($byContext)->flatten()->contains(null);
        $allUserOrgs = collect();
        if ($hasNullFallback && method_exists($user, 'accessibleOrganizationIds')) {
            $ids = $user->accessibleOrganizationIds();
            $allUserOrgs = $orgModel::whereIn('id', $ids)->get(['id', 'key', 'name']);
        }

        $result = [];
        foreach ($byContext as $context => $orgIds) {
            $list = collect($orgIds)->flatMap(function ($id) use ($orgs, $allUserOrgs) {
                if ($id === null) return $allUserOrgs;
                return $orgs->has($id) ? [$orgs->get($id)] : [];
            })->unique('id')->values()->toArray();
            $result[$context] = $list;
        }

        return $result;
    }

    /**
     * GET /auth/me/permissions
     *
     * Returns the authenticated user's roles and resolved permission names.
     * Useful for frontend to decide what to show/hide without extra round-trips.
     *
     * {
     *   "roles": ["admin", "manager"],
     *   "permissions": ["users.view", "users.manage", "clients.view"]
     * }
     */
    public function mePermissions(Request $request): JsonResponse
    {
        $user = $request->user();

        $roles       = [];
        $permissions = [];

        if (method_exists($user, 'roles')) {
            $roles = $user->getRoleNames()->values()->all();

            // Collect all named permissions via roles + direct grants
            $viaRoles = $user->roles()
                ->with('permissions')
                ->get()
                ->flatMap(fn ($role) => $role->permissions->pluck('name'));

            $direct = $user->directPermissions()->pluck('name');

            $permissions = $viaRoles->merge($direct)->unique()->values()->all();
        }

        return response()->json(compact('roles', 'permissions'));
    }

    public function refresh(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);
        $new = (new RefreshToken(app(\Innertia\Auth\Services\JwtService::class), $token))->execute();

        return response()->json(['token' => $new]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);
        (new Logout(app(\Innertia\Auth\Services\JwtService::class), $token))->execute();

        return response()->json(['message' => 'Logged out.']);
    }

    protected function extractToken(Request $request): string
    {
        $header = $request->header('Authorization', '');
        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    }
}
