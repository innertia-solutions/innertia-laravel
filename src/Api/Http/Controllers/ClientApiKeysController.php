<?php

namespace Innertia\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Innertia\Api\ApiPermissions;
use Innertia\Api\Models\Client;
use Innertia\Api\UseCases\CreateApiKey;
use Innertia\Api\UseCases\RevokeApiKey;

class ClientApiKeysController
{
    public function index(string $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        return response()->json($client->apiKeys()->orderBy('created_at', 'desc')->get());
    }

    public function permissions(): JsonResponse
    {
        return response()->json(ApiPermissions::all());
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        $validKeys = ApiPermissions::keys();

        $data = $request->validate([
            'name'          => 'required|string',
            'permissions'   => 'array',
            'permissions.*' => 'string|in:' . implode(',', $validKeys),
            'expires_at'    => 'nullable|date',
        ]);

        $result = (new CreateApiKey(
            client:      $client,
            name:        $data['name'],
            permissions: $data['permissions'] ?? [],
            expiresAt:   isset($data['expires_at']) ? \Carbon\Carbon::parse($data['expires_at']) : null,
        ))->execute();

        return response()->json([
            'raw_key' => $result['raw_key'],  // única vez expuesta
            'api_key' => $result['api_key'],
        ], 201);
    }

    public function revoke(string $id, string $keyId): JsonResponse
    {
        $client = Client::findOrFail($id);
        $apiKey = $client->apiKeys()->findOrFail($keyId);

        (new RevokeApiKey($apiKey))->execute();

        return response()->json(['message' => 'API key revoked.']);
    }
}
