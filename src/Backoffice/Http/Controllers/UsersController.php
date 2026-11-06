<?php

namespace Innertia\Backoffice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Innertia\Auth\UseCases\Login;
use Innertia\Auth\RBAC\UseCases\CreateRole;
use Innertia\Auth\RBAC\UseCases\AssignRole;
use Innertia\Auth\RBAC\UseCases\RemoveRole;
use Illuminate\Validation\Rule;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Facades\DataTable;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationsFeature;

class UsersController extends Controller
{
    // ── List ──────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $model = config('innertia.auth.user_model');

        $statusExpr = "CASE WHEN users.force_password_change = true THEN 'password_pending' WHEN users.email_verified_at IS NULL AND users.seen_at IS NULL THEN 'invited' WHEN users.email_verified_at IS NULL THEN 'unverified' WHEN users.seen_at IS NULL THEN 'never_logged_in' ELSE 'active' END";

        $statuses = array_filter((array) $request->input('statuses', []));

        return DataTable::create('users')
            ->columns(['name', 'email', 'force_password_change', 'two_factor_enabled', 'seen_at', 'created_at', 'created_by'])
            ->addCalculatedColumn('"roles"', "(SELECT COALESCE(jsonb_agg(jsonb_build_object('id', roles.id, 'name', roles.name)), '[]'::jsonb) FROM roles INNER JOIN model_roles ON roles.id::text = model_roles.role_id::text WHERE model_roles.model_id::text = users.id::text AND model_roles.model_type LIKE '%User')")
            ->addCalculatedColumn('"otp_configured"', 'users.two_factor_secret IS NOT NULL')
            ->addCalculatedColumn('"status"', $statusExpr)
            ->addCalculatedColumn('"created_by_name"', '(SELECT u.name FROM users u WHERE u.id::text = users.created_by::text LIMIT 1)')
            ->prepareQuery(function ($query) use ($statuses, $statusExpr) {
                if (!empty($statuses)) {
                    $query->whereRaw("({$statusExpr}) IN (" . implode(',', array_fill(0, count($statuses), '?')) . ")", $statuses);
                }
                $this->applyManageableScope($query);
                $this->applyIndexScope($query);

                return $query;
            })
            ->enableExport()
            ->render($model, $request, 'created_at', 'desc');
    }

    /**
     * Hook para que los productos restrinjan el listado de usuarios (p.ej. por
     * contexto/rol). No-op por defecto — extendé el controller y montalo vía
     * `Routes::register(prefix, TuUsersController::class)`.
     */
    protected function applyIndexScope($query): void
    {
        // no-op
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(string $id): JsonResponse
    {
        $model = config('innertia.auth.user_model');
        $user  = $this->findUser($id);

        $data = $user->toArray();

        if ($this->modelHasRoles($model)) {
            $data['roles']       = $user->roles()->get(['roles.id', 'roles.name', 'roles.description']);
            $data['permissions'] = $user->getRoleNames()->all();
        }

        return response()->json($data);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $model = config('innertia.auth.user_model');

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $data['password']   = Hash::make($data['password']);
        $data['created_by'] = auth()->id();

        $user = $model::create($data);

        if ($request->filled('role') && $this->modelHasRoles($model)) {
            (new AssignRole($user->id, $request->role))->execute();
        }

        return response()->json($user->makeHidden('password'), 201);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, string $id): JsonResponse
    {
        $model = config('innertia.auth.user_model');
        $user  = $this->findUser($id);

        $data = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'email'    => ['sometimes', 'email', Rule::unique((new $model)->getTable(), 'email')->ignore($id)],
            'password' => 'sometimes|string|min:8',
            // Código por correo por usuario (null = según la configuración general)
            'otp_mode' => ['sometimes', 'nullable', Rule::in([Login::OTP_ALWAYS, Login::OTP_NEVER])],
        ]);

        // Productos sin la columna users.otp_mode: se ignora el campo.
        if (array_key_exists('otp_mode', $data) && ! Schema::hasColumn($user->getTable(), 'otp_mode')) {
            unset($data['otp_mode']);
        }

        if (array_key_exists('email', $data) || array_key_exists('password', $data)) {
            $this->ensureIdentityEditable($user);
        }

        if (isset($data['password'])) {
            $data['password']             = Hash::make($data['password']);
            $data['force_password_change'] = false;
        }

        $user->update($data);

        return response()->json($user->fresh()->makeHidden('password'));
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(string $id): JsonResponse
    {
        abort_unless(config('innertia.backoffice.users.allow_delete', false), 403, 'User deletion is disabled.');

        $user = $this->findUser($id);
        $user->delete();

        return response()->json(['deleted' => true]);
    }

    // ── Roles ─────────────────────────────────────────────────────────────────

    public function roles(string $id): JsonResponse
    {
        $model = config('innertia.auth.user_model');
        $user  = $this->findUser($id);

        abort_unless($this->modelHasRoles($model), 501, 'User model does not use HasRoles.');

        return response()->json($user->roles()->get(['roles.id', 'roles.name', 'roles.description']));
    }

    public function assignRole(Request $request, string $id): JsonResponse
    {
        $this->findUser($id);
        $request->validate(['role' => 'required|string']);

        (new AssignRole($id, $request->role))->execute();

        return response()->json(['assigned' => true]);
    }

    public function removeRole(string $id, string $role): JsonResponse
    {
        $this->findUser($id);

        (new RemoveRole($id, $role))->execute();

        return response()->json(['removed' => true]);
    }

    // ── Contexts ──────────────────────────────────────────────────────────────

    public function contexts(string $id): JsonResponse
    {
        $user = $this->findUser($id);
        abort_unless(method_exists($user, 'contextKeys'), 501, 'User model does not use HasContexts.');

        return response()->json($user->contextKeys());
    }

    public function grantContext(Request $request, string $id): JsonResponse
    {
        $user = $this->findUser($id);
        $request->validate(['context' => 'required|string']);

        $user->grantContext($request->context);

        return response()->json(['message' => 'Context granted.']);
    }

    public function revokeContext(string $id, string $context): JsonResponse
    {
        $user = $this->findUser($id);
        $user->revokeContext($context);

        return response()->json(['message' => 'Context revoked.']);
    }

    public function syncContexts(Request $request, string $id): JsonResponse
    {
        $user = $this->findUser($id);
        $request->validate([
            'contexts'   => 'required|array',
            'contexts.*' => 'string',
        ]);

        $user->syncContexts($request->contexts);

        return response()->json(['contexts' => $user->contextKeys()]);
    }

    // ── Sessions ──────────────────────────────────────────────────────────────

    public function sessions(string $id): JsonResponse
    {
        $this->findUser($id, withTrashed: true);

        $sessions = DB::table('user_tokens')
            ->where('user_id', $id)
            ->where('active', true)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($sessions);
    }

    public function revokeSession(string $id, string $sessionId): JsonResponse
    {
        $this->findUser($id, withTrashed: true);

        DB::table('user_tokens')
            ->where('id', $sessionId)
            ->where('user_id', $id)
            ->update(['active' => false]);

        return response()->json(['revoked' => true]);
    }

    public function revokeAllSessions(string $id): JsonResponse
    {
        $this->findUser($id, withTrashed: true);

        DB::table('user_tokens')
            ->where('user_id', $id)
            ->update(['active' => false]);

        return response()->json(['revoked' => true]);
    }

    // ── Password + Reactivate ─────────────────────────────────────────────────

    public function reactivate(string $id): JsonResponse
    {
        $user = $this->findUser($id, withTrashed: true);

        $user->restore();

        Password::broker()->sendResetLink(['email' => $user->email]);

        return response()->json(['reactivated' => true]);
    }

    public function resetPassword(Request $request, string $id): JsonResponse
    {
        $user = $this->findUser($id, withTrashed: true);

        $data = $request->validate([
            'mode'     => 'required|in:email,manual',
            'password' => 'required_if:mode,manual|string|min:8',
        ]);

        if ($data['mode'] === 'email') {
            Password::broker()->sendResetLink(['email' => $user->email]);

            return response()->json(['sent' => true]);
        }

        $this->ensureIdentityEditable($user);

        $user->update([
            'password'              => Hash::make($data['password']),
            'force_password_change' => true,
        ]);

        return response()->json(['updated' => true]);
    }

    // ── Activity ──────────────────────────────────────────────────────────────

    public function activity(Request $request, string $id): JsonResponse
    {
        $this->findUser($id, withTrashed: true);

        $perPage = $request->integer('per_page', 15);

        $query = DB::table('activity_logs')
            ->where('user_id', $id)
            ->orderBy('created_at', 'desc');

        return response()->json($query->paginate($perPage));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Resuelve un usuario que el actor puede administrar (ver
     * applyManageableScope). Fuera de alcance → 404, sin revelar existencia.
     */
    protected function findUser(string $id, bool $withTrashed = false): mixed
    {
        $model = config('innertia.auth.user_model');
        $query = $model::query();

        if ($withTrashed && method_exists($model, 'bootSoftDeletes')) {
            $query->withTrashed();
        }

        $this->applyManageableScope($query);

        return $query->findOrFail($id);
    }

    /**
     * Aislamiento base de la administración de usuarios (genérico, no de negocio):
     *
     *  - Tenant: con tenancy y tenant activo, solo usuarios con alguna fila en
     *    user_contexts de ese tenant. Un usuario de varios tenants se ve desde
     *    cada uno de ellos.
     *  - Organization (OrganizationsFeature activo): si el actor está limitado a
     *    ciertas orgs, solo usuarios con contexto en alguna de esas orgs. El
     *    acceso "todas las orgs" (organization_id NULL) solo lo administra otro
     *    actor con acceso a todas. is_platform_admin no se limita por org.
     *
     * Qué acciones puede hacer cada perfil es lógica del producto (permisos,
     * middleware); esto solo define a QUIÉN puede alcanzar.
     */
    protected function applyManageableScope($query): void
    {
        if (! Innertia::tenancyEnabled() || Innertia::tenant() === null) {
            return;
        }

        $tenantId = (string) Innertia::tenant()->getKey();
        $userKey  = 'CAST(' . $query->getModel()->getQualifiedKeyName() . ' AS TEXT)';

        $query->whereExists(function ($q) use ($tenantId, $userKey) {
            $q->select(DB::raw(1))
                ->from('user_contexts')
                ->whereRaw("user_contexts.user_id = {$userKey}")
                ->where('user_contexts.tenant_id', $tenantId);
        });

        $actor = auth()->user();
        if (! OrganizationsFeature::isActive() || ! $actor || ($actor->is_platform_admin ?? false)) {
            return;
        }

        if ($this->actorHasAllOrganizations($actor, $tenantId)) {
            return;
        }

        $orgIds = method_exists($actor, 'accessibleOrganizationIds') ? $actor->accessibleOrganizationIds() : [];

        if ($orgIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereExists(function ($q) use ($tenantId, $userKey, $orgIds) {
            $q->select(DB::raw(1))
                ->from('user_contexts')
                ->whereRaw("user_contexts.user_id = {$userKey}")
                ->where('user_contexts.tenant_id', $tenantId)
                ->whereIn('user_contexts.organization_id', $orgIds);
        });
        $query->whereNotExists(function ($q) use ($tenantId, $userKey) {
            $q->select(DB::raw(1))
                ->from('user_contexts')
                ->whereRaw("user_contexts.user_id = {$userKey}")
                ->where('user_contexts.tenant_id', $tenantId)
                ->whereNull('user_contexts.organization_id');
        });
    }

    private function actorHasAllOrganizations($actor, string $tenantId): bool
    {
        return DB::table('user_contexts')
            ->where('user_id', (string) $actor->getKey())
            ->where('tenant_id', $tenantId)
            ->whereNull('organization_id')
            ->exists();
    }

    /**
     * Email y contraseña son identidad global: si el usuario también pertenece
     * a otro tenant, un admin de este tenant no puede cambiarlos (afectaría su
     * cuenta en el otro). Se usa reset-password por correo.
     */
    protected function ensureIdentityEditable($user): void
    {
        if (! Innertia::tenancyEnabled() || Innertia::tenant() === null) {
            return;
        }

        $sharedElsewhere = DB::table('user_contexts')
            ->where('user_id', (string) $user->getKey())
            ->where('tenant_id', '!=', (string) Innertia::tenant()->getKey())
            ->exists();

        if ($sharedElsewhere) {
            throw new ForbiddenException('El usuario pertenece también a otra cuenta: su email y contraseña solo se cambian vía restablecimiento por correo.');
        }
    }

    private function modelHasRoles(string $model): bool
    {
        return method_exists($model, 'roles');
    }
}
