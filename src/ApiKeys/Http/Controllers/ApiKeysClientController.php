<?php

namespace Innertia\ApiKeys\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\ApiKeys\Models\ApiKey;
use Innertia\ApiKeys\Services\ApiKeyService;
use Innertia\Exceptions\ForbiddenException;

/**
 * Client-facing controller — allows an API key with 'api_keys.manage' permission
 * to create and revoke other API keys, but never with more permissions than itself.
 *
 * Routes (in api.clients.php, protected by apikey middleware):
 *   GET    /v1/api-keys              → index   (lists keys created by this key)
 *   POST   /v1/api-keys              → store   (requires api_keys.manage)
 *   DELETE /v1/api-keys/{id}         → destroy (requires api_keys.manage)
 */
class ApiKeysClientController extends Controller
{
    public function __construct(private readonly ApiKeyService $service) {}

    public function index(Request $request): JsonResponse
    {
        $caller = $request->attributes->get('api_key');

        $keys = ApiKey::active()
            ->where('created_by_key_id', $caller->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($keys);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var ApiKey $caller */
        $caller = $request->attributes->get('api_key');

        if (! $caller->hasPermission('api_keys.manage')) {
            return response()->json([
                'message' => 'API key does not have permission: api_keys.manage.',
                'error'   => 'forbidden',
            ], 403);
        }

        $data = $request->validate([
            'name'          => 'required|string|max:100',
            'permissions'   => 'required|array|min:1',
            'permissions.*' => 'string',
            'expires_at'    => 'nullable|date|after:now',
        ]);

        // No privilege escalation — requested permissions must be subset of caller's
        $escalated = array_diff($data['permissions'], $caller->permissions ?? []);
        if ($escalated) {
            return response()->json([
                'message' => 'Cannot grant permissions not held by the calling key: ' . implode(', ', $escalated),
                'error'   => 'forbidden',
            ], 403);
        }

        $result = $this->service->create(
            name:        $data['name'],
            permissions: $data['permissions'],
            tenantId:    $caller->tenant_id ?? null,
            expiresAt:   isset($data['expires_at']) ? \Carbon\Carbon::parse($data['expires_at']) : null,
            createdByKeyId: $caller->id,
        );

        return response()->json([
            'key' => $result['key'],
            'raw' => $result['raw'],
        ], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var ApiKey $caller */
        $caller = $request->attributes->get('api_key');

        if (! $caller->hasPermission('api_keys.manage')) {
            return response()->json([
                'message' => 'API key does not have permission: api_keys.manage.',
                'error'   => 'forbidden',
            ], 403);
        }

        // Can only revoke keys it created
        $target = ApiKey::where('id', $id)
            ->where('created_by_key_id', $caller->id)
            ->firstOrFail();

        $target->revoke();

        return response()->json(['message' => 'API key revoked.']);
    }
}
