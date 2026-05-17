<?php

namespace Innertia\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Innertia\Api\Models\Client;
use Innertia\Api\UseCases\RegisterClient;

class ClientsController
{
    public function index(Request $request): JsonResponse
    {
        $clients = Client::query()
            ->when($request->product, fn ($q) => $q->where('product', $request->product))
            ->when($request->status,  fn ($q) => $q->where('status', $request->status))
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($clients);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product'        => 'required|string',
            'tenant'         => 'required|string',
            'name'           => 'required|string',
            'tags'           => 'array',
            'options'        => 'array',
            'first_key_name' => 'string',
            'first_key_permissions' => 'array',
        ]);

        $result = (new RegisterClient(
            product:       $data['product'],
            tenant:        $data['tenant'],
            name:          $data['name'],
            tags:          $data['tags'] ?? [],
            options:       $data['options'] ?? [],
            firstKeyName:  $data['first_key_name'] ?? 'Default',
            firstKeyPerms: $data['first_key_permissions'] ?? [],
        ))->execute();

        return response()->json([
            'client'  => $result['client'],
            'raw_key' => $result['raw_key'],  // única vez expuesta
            'api_key' => $result['api_key'],
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        return response()->json($client->load('activeApiKeys'));
    }

    public function suspend(string $id): JsonResponse
    {
        Client::findOrFail($id)->suspend();
        return response()->json(['message' => 'Client suspended.']);
    }

    public function reactivate(string $id): JsonResponse
    {
        Client::findOrFail($id)->reactivate();
        return response()->json(['message' => 'Client reactivated.']);
    }

    public function destroy(string $id): JsonResponse
    {
        Client::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
