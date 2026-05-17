<?php

namespace Innertia\ApiKeys\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\ApiKeys\Services\ApiKeyService;

/**
 * Backoffice controller — manages API keys for the current tenant.
 *
 * Routes (registered under backoffice prefix with auth middleware):
 *   GET    /backoffice/api-keys              → index
 *   POST   /backoffice/api-keys              → store
 *   DELETE /backoffice/api-keys/{id}         → destroy
 *   GET    /backoffice/api-keys/permissions  → permissions
 *
 * Also for user-scoped keys (if the user model uses HasApiKeys):
 *   GET    /backoffice/api-keys/user         → userIndex
 *   POST   /backoffice/api-keys/user         → userStore
 *   DELETE /backoffice/api-keys/user/{id}    → userDestroy
 */
class ApiKeysController extends Controller
{
    public function __construct(private readonly ApiKeyService $service) {}

    // ── Tenant keys ────────────────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        return response()->json($this->service->list());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'permissions' => 'array',
            'permissions.*' => 'string',
            'expires_at'  => 'nullable|date|after:now',
        ]);

        $result = $this->service->create(
            name:        $data['name'],
            permissions: $data['permissions'] ?? [],
            expiresAt:   isset($data['expires_at']) ? \Carbon\Carbon::parse($data['expires_at']) : null,
        );

        return response()->json([
            'key' => $result['key'],
            'raw' => $result['raw'], // shown once only
        ], 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->revoke($id);
        return response()->json(['message' => 'API key revoked.']);
    }

    public function permissions(): JsonResponse
    {
        return response()->json([
            'tenant' => $this->service->availablePermissions('tenant'),
            'user'   => $this->service->availablePermissions('user'),
        ]);
    }

    // ── User keys ──────────────────────────────────────────────────────────────

    public function userIndex(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->apiKeys()->active()->orderByDesc('created_at')->get()
        );
    }

    public function userStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:100',
            'permissions'   => 'array',
            'permissions.*' => 'string',
            'expires_at'    => 'nullable|date|after:now',
        ]);

        $result = $request->user()->createApiKey(
            name:        $data['name'],
            permissions: $data['permissions'] ?? [],
            expiresAt:   isset($data['expires_at']) ? \Carbon\Carbon::parse($data['expires_at']) : null,
        );

        return response()->json([
            'key' => $result['key'],
            'raw' => $result['raw'],
        ], 201);
    }

    public function userDestroy(Request $request, string $id): JsonResponse
    {
        $request->user()->revokeApiKey($id);
        return response()->json(['message' => 'API key revoked.']);
    }
}
