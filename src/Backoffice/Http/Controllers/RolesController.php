<?php

namespace Innertia\Backoffice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Auth\RBAC\Models\Role;
use Innertia\Auth\RBAC\UseCases\CreateRole;
use Innertia\Auth\RBAC\UseCases\DeleteRole;
use Innertia\Auth\RBAC\UseCases\SyncRolePermissions;
use Innertia\Auth\RBAC\UseCases\UpdateRole;
use Innertia\Facades\DataTable;

class RolesController extends Controller
{
    // ── List ──────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Role::query();

        // El scope por tenant solo aplica en saas; en app mode no hay tenant_id.
        if (config('innertia.mode') === 'saas') {
            $query->where('tenant_id', $this->tenantId());
        }

        return DataTable::create('roles')
            ->columns(['name', 'description', 'created_at'])
            ->addCalculatedColumn('"permissions_count"', '(SELECT COUNT(*) FROM role_permissions WHERE role_permissions.role_id = roles.id)')
            ->addCalculatedColumn('"users_count"', '(SELECT COUNT(*) FROM model_roles WHERE model_roles.role_id = roles.id)')
            ->addCalculatedColumn('"permissions"', "(SELECT COALESCE(json_agg(json_build_object('name', p.name, 'description', p.description)), '[]'::json) FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = roles.id)")
            ->render($query, $request);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(string $id): JsonResponse
    {
        $role = Role::with('permissions')->findOrFail($id);

        return response()->json([
            'id'          => $role->id,
            'name'        => $role->name,
            'description' => $role->description,
            'tenant_id'   => $role->tenant_id,
            'permissions' => $role->permissions->map(fn ($p) => [
                'id'          => $p->id,
                'name'        => $p->name,
                'description' => $p->description,
            ]),
            'users_count' => \DB::table('model_roles')
                ->where('role_id', $role->id)
                ->count(),
            'created_at'  => $role->created_at,
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        $role = (new CreateRole($request->name, $request->description))->execute();

        return response()->json($role, 201);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        $role = (new UpdateRole($id, $request->name, $request->description))->execute();

        return response()->json($role);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(string $id): JsonResponse
    {
        (new DeleteRole($id))->execute();

        return response()->json(['deleted' => true]);
    }

    // ── Sync permissions ──────────────────────────────────────────────────────

    /**
     * POST /backoffice/roles/{id}/permissions
     *
     * Replaces the role's full permission set.
     * Body: { "permissions": ["users.view", "clients.manage"] }
     */
    public function syncPermissions(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'string',
        ]);

        $role = (new SyncRolePermissions($id, $request->permissions))->execute();

        return response()->json($role);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function tenantId(): ?string
    {
        return \Innertia\Facades\Innertia::tenant()
            ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
            : null;
    }
}
