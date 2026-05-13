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
            'app'      => 'required|string',
        ]);

        $result = (new Login(
            email:    $data['email'],
            password: $data['password'],
            app:      $data['app'],
        ))->execute();

        return response()->json($result);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $user->toArray();

        if (method_exists($user, 'appKeys')) {
            $data['apps'] = $user->appKeys();
        }

        return response()->json($data);
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
        $new   = (new RefreshToken(app(\Innertia\Auth\Services\JwtService::class)))->execute($token);

        return response()->json(['token' => $new]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);
        (new Logout(app(\Innertia\Auth\Services\JwtService::class)))->execute($token);

        return response()->json(['message' => 'Logged out.']);
    }

    protected function extractToken(Request $request): string
    {
        $header = $request->header('Authorization', '');
        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    }
}
