<?php

namespace Innertia\Backoffice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Innertia\Auth\RBAC\UseCases\CreateRole;
use Innertia\Auth\RBAC\UseCases\AssignRole;
use Innertia\Auth\RBAC\UseCases\RemoveRole;
use Innertia\Facades\DataTable;

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
            ->addCalculatedColumn('"roles"', "(SELECT COALESCE(jsonb_agg(jsonb_build_object('id', roles.id, 'name', roles.name)), '[]'::jsonb) FROM roles INNER JOIN model_roles ON roles.id::text = model_roles.role_id::text WHERE model_roles.model_id = users.id::text AND model_roles.model_type LIKE '%User')")
            ->addCalculatedColumn('"otp_configured"', 'users.two_factor_secret IS NOT NULL')
            ->addCalculatedColumn('"status"', $statusExpr)
            ->addCalculatedColumn('"created_by_name"', '(SELECT u.name FROM users u WHERE u.id::text = users.created_by::text LIMIT 1)')
            ->prepareQuery(function ($query) use ($statuses, $statusExpr) {
                if (!empty($statuses)) {
                    $query->whereRaw("({$statusExpr}) IN (" . implode(',', array_fill(0, count($statuses), '?')) . ")", $statuses);
                }
                return $query;
            })
            ->enableExport()
            ->render($model, $request, 'created_at', 'desc');
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(string $id): JsonResponse
    {
        $model = config('innertia.auth.user_model');
        $user  = $model::findOrFail($id);

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
        $user  = $model::findOrFail($id);

        $data = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'email'    => "sometimes|email|unique:users,email,{$id}",
            'password' => 'sometimes|string|min:8',
        ]);

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

        $model = config('innertia.auth.user_model');
        $user  = $model::findOrFail($id);
        $user->delete();

        return response()->json(['deleted' => true]);
    }

    // ── Roles ─────────────────────────────────────────────────────────────────

    public function roles(string $id): JsonResponse
    {
        $model = config('innertia.auth.user_model');
        $user  = $model::findOrFail($id);

        abort_unless($this->modelHasRoles($model), 501, 'User model does not use HasRoles.');

        return response()->json($user->roles()->get(['roles.id', 'roles.name', 'roles.description']));
    }

    public function assignRole(Request $request, string $id): JsonResponse
    {
        $request->validate(['role' => 'required|string']);

        (new AssignRole($id, $request->role))->execute();

        return response()->json(['assigned' => true]);
    }

    public function removeRole(string $id, string $role): JsonResponse
    {
        (new RemoveRole($id, $role))->execute();

        return response()->json(['removed' => true]);
    }

    // ── Apps ──────────────────────────────────────────────────────────────────

    public function apps(string $id): JsonResponse
    {
        $model = config('innertia.auth.user_model');
        $user  = $model::findOrFail($id);

        abort_unless(method_exists($user, 'appKeys'), 501, 'User model does not use HasApps.');

        return response()->json($user->appKeys());
    }

    public function grantApp(Request $request, string $id): JsonResponse
    {
        $request->validate(['app' => 'required|string']);

        $model = config('innertia.auth.user_model');
        $user  = $model::findOrFail($id);

        $user->grantApp($request->app);

        return response()->json(['granted' => true]);
    }

    public function revokeApp(string $id, string $app): JsonResponse
    {
        $model = config('innertia.auth.user_model');
        $user  = $model::findOrFail($id);

        $user->revokeApp($app);

        return response()->json(['revoked' => true]);
    }

    public function syncApps(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'apps'   => 'required|array',
            'apps.*' => 'string',
        ]);

        $model = config('innertia.auth.user_model');
        $user  = $model::findOrFail($id);

        $user->syncApps($request->apps);

        return response()->json(['apps' => $user->appKeys()]);
    }

    // ── Sessions ──────────────────────────────────────────────────────────────

    public function sessions(string $id): JsonResponse
    {
        $model = config('innertia.auth.user_model');
        $model::withTrashed()->findOrFail($id);

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
        $model = config('innertia.auth.user_model');
        $model::withTrashed()->findOrFail($id);

        DB::table('user_tokens')
            ->where('id', $sessionId)
            ->where('user_id', $id)
            ->update(['active' => false]);

        return response()->json(['revoked' => true]);
    }

    public function revokeAllSessions(string $id): JsonResponse
    {
        $model = config('innertia.auth.user_model');
        $model::withTrashed()->findOrFail($id);

        DB::table('user_tokens')
            ->where('user_id', $id)
            ->update(['active' => false]);

        return response()->json(['revoked' => true]);
    }

    // ── Password + Reactivate ─────────────────────────────────────────────────

    public function reactivate(string $id): JsonResponse
    {
        $model = config('innertia.auth.user_model');
        $user  = $model::withTrashed()->findOrFail($id);

        $user->restore();

        Password::broker()->sendResetLink(['email' => $user->email]);

        return response()->json(['reactivated' => true]);
    }

    public function resetPassword(Request $request, string $id): JsonResponse
    {
        $model = config('innertia.auth.user_model');
        $user  = $model::withTrashed()->findOrFail($id);

        $data = $request->validate([
            'mode'     => 'required|in:email,manual',
            'password' => 'required_if:mode,manual|string|min:8',
        ]);

        if ($data['mode'] === 'email') {
            Password::broker()->sendResetLink(['email' => $user->email]);

            return response()->json(['sent' => true]);
        }

        $user->update([
            'password'              => Hash::make($data['password']),
            'force_password_change' => true,
        ]);

        return response()->json(['updated' => true]);
    }

    // ── Activity ──────────────────────────────────────────────────────────────

    public function activity(Request $request, string $id): JsonResponse
    {
        $model = config('innertia.auth.user_model');
        $model::withTrashed()->findOrFail($id);

        $perPage = $request->integer('per_page', 15);

        $query = DB::table('activity_logs')
            ->where('user_id', $id)
            ->orderBy('created_at', 'desc');

        return response()->json($query->paginate($perPage));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function modelHasRoles(string $model): bool
    {
        return method_exists($model, 'roles');
    }
}
