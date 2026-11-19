<?php

namespace Innertia\PublicApi\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Innertia\ApiKeys\ApiScopes;
use Innertia\ApiKeys\Models\ApiKey;
use Innertia\ApiKeys\UseCases\CreateIntegrationKey;
use Innertia\ApiKeys\UseCases\RevokeIntegrationKey;
use Innertia\ApiKeys\UseCases\RotateIntegrationKey;
use Innertia\Facades\Innertia;
use Innertia\PublicApi\Http\CursorPage;
use Innertia\PublicApi\Models\ApiRequestLog;

/**
 * Management of integration keys for the active space (mounted by \Innertia\PublicApi\Routes::manage()
 * inside the product's auth + permission group). Secrets are returned once, at create/rotate.
 */
class IntegrationKeysController extends Controller
{
    public function index(): JsonResponse
    {
        $keys = $this->query()->orderByDesc('created_at')->get();

        return response()->json(['data' => $keys->map->toApiArray()->values()]);
    }

    public function scopes(): JsonResponse
    {
        return response()->json(['data' => array_values(ApiScopes::all())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'scopes'          => 'required|array|min:1',
            'scopes.*'        => 'string|max:100',
            'organization_id' => 'nullable',
            'expires_at'      => 'nullable|date|after:now',
        ]);

        $result = (new CreateIntegrationKey(
            name: $data['name'],
            scopes: $data['scopes'],
            organizationId: isset($data['organization_id']) ? (string) $data['organization_id'] : null,
            expiresAt: isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
        ))->execute();

        return response()->json(['data' => $result['key']->toApiArray(), 'secret' => $result['secret']], 201);
    }

    public function rotate(string $id): JsonResponse
    {
        $key = $this->query()->active()->findOrFail($id);
        $result = (new RotateIntegrationKey($key))->execute();

        return response()->json([
            'data'     => $result['key']->toApiArray(),
            'secret'   => $result['secret'],
            'previous' => ['id' => $result['previous']->id, 'expires_at' => $result['previous']->expires_at?->toIso8601String()],
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $key = (new RevokeIntegrationKey($this->query()->findOrFail($id)))->execute();

        return response()->json(['data' => $key->toApiArray()]);
    }

    public function requests(Request $request, string $id): JsonResponse
    {
        $key = $this->query()->findOrFail($id);

        return CursorPage::respond(
            $request,
            ApiRequestLog::query()->where('api_key_id', $key->id),
            fn (ApiRequestLog $log) => $log->toApiArray(),
        );
    }

    private function query(): Builder
    {
        $query = ApiKey::query()->where('type', ApiKey::TYPE_INTEGRATION);

        if (Innertia::tenancyEnabled()) {
            $query->where('tenant_id', (string) Innertia::tenant()?->getKey());
        }

        return $query;
    }
}
