<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * GET  /auth/me/preferences         — lista preferencias públicas del usuario
 * PUT  /auth/me/preferences/{key}   — crea o actualiza una preferencia
 * DELETE /auth/me/preferences/{key} — elimina una preferencia
 */
class PreferencesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! method_exists($user, 'preferences')) {
            return response()->json([]);
        }

        return response()->json(
            $user->preferences()->onlyPublic()->toArray()
        );
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'value' => 'required',
            'cast'  => 'sometimes|in:string,boolean,integer,json,encrypted',
        ]);

        $user = $request->user();

        if (! method_exists($user, 'setPreference')) {
            abort(501, 'HasPreferences trait not present on User model.');
        }

        $config = $user->setPreference($key, $data['value'], $data['cast'] ?? 'string');

        return response()->json([
            'key'   => $config->key,
            'value' => $config->getValue(),
        ]);
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        $user = $request->user();

        if (method_exists($user, 'preferences')) {
            $user->preferences()->delete($key);
        }

        return response()->json(['deleted' => true]);
    }
}
