<?php
declare(strict_types=1);
namespace Innertia\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Api\Models\ApiKey;
use Innertia\Api\Models\Organization;
use Innertia\Api\UseCases\CreateApiKey;
use Innertia\Api\UseCases\RevokeApiKey;

class ApiKeysController extends Controller
{
    /** GET /olimpo/organizations/{organization}/api-keys */
    public function index(Organization $organization): JsonResponse
    {
        return response()->json(
            $organization->apiKeys()->active()->get()
        );
    }

    /** POST /olimpo/organizations/{organization}/api-keys */
    public function store(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $result = (new CreateApiKey(
            organization: $organization,
            name:         $data['name'],
        ))->execute();

        return response()->json([
            'raw_key' => $result['raw_key'],
            'api_key' => $result['api_key'],
        ], 201);
    }

    /** DELETE /olimpo/organizations/{organization}/api-keys/{apiKey} */
    public function revoke(Organization $organization, ApiKey $apiKey): JsonResponse
    {
        abort_if($apiKey->organization_id !== $organization->id, 404);

        (new RevokeApiKey($apiKey))->execute();

        return response()->json(null, 204);
    }
}
