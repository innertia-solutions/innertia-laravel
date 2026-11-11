<?php

namespace Innertia\Passkeys\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Innertia\Passkeys\Models\Passkey;
use Innertia\Passkeys\UseCases\BeginPasskeyRegistration;
use Innertia\Passkeys\UseCases\FinishPasskeyRegistration;
use Innertia\Passkeys\UseCases\RevokePasskeys;

class MyPasskeysController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Passkey::where('user_id', (string) $request->user()->getAuthIdentifier())
            ->orderByDesc('created_at')->get()
            ->map->toPublicArray()->values();

        return response()->json(['data' => $items]);
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json((new BeginPasskeyRegistration($request->user()))->execute());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'      => 'required|string',
            'response' => 'required|array',
            'name'     => 'nullable|string|max:120',
        ]);

        $passkey = (new FinishPasskeyRegistration($request->user(), $data['key'], $data['response'], $data['name'] ?? null))->execute();

        return response()->json($passkey->toPublicArray(), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120']);
        $passkey = $this->own($request, $id);
        $passkey->update(['name' => trim($data['name'])]);

        return response()->json($passkey->toPublicArray());
    }

    public function destroy(Request $request, string $id): Response
    {
        $passkey = $this->own($request, $id);
        $userId = (string) $request->user()->getAuthIdentifier();
        (new RevokePasskeys($userId, $passkey->id, $userId))->execute();

        return response()->noContent();
    }

    private function own(Request $request, string $id): Passkey
    {
        return Passkey::where('user_id', (string) $request->user()->getAuthIdentifier())->findOrFail($id);
    }
}
