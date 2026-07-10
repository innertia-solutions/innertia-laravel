<?php

namespace Innertia\Saas\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Innertia\Saas\UseCases\CreateOpenTenant;

class CreateGymController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120']);

        $result = (new CreateOpenTenant(
            name: $data['name'],
            ownerUserId: (string) $request->user()->getKey(),
        ))->execute();

        return response()->json(['gym' => [
            'key'    => $result['tenant']->key,
            'name'   => $result['tenant']->name,
            'status' => $result['tenant']->status,
        ]], 201);
    }
}
