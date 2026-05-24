<?php

namespace Innertia\Settings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * GET    /preferences          — lista preferencias públicas del usuario
 * GET    /preferences/{module} — filtra por prefix `{module}.` (ej. 'tables', 'dashboard')
 * PUT    /preferences/{key}    — crea o actualiza una preferencia
 * DELETE /preferences/{key}    — elimina una preferencia
 *
 * Las preferencias se guardan con convención `{module}.{key}` (ej. `tables.workers.columns`,
 * `dashboard.layout`) para permitir filtrado por módulo.
 *
 * Convención de aliases: las rutas viejas `/auth/me/preferences*` quedan como
 * aliases deprecated hacia estos endpoints durante 1-2 versiones mayores.
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

    /**
     * GET /preferences/{module}
     *
     * Filtra las prefs que empiezan con `{module}.`. Devuelve solo el sub-set,
     * con las keys SIN el prefix (ej. `tables.workers.columns` → `workers.columns`).
     */
    public function showByModule(Request $request, string $module): JsonResponse
    {
        $user = $request->user();

        if (! method_exists($user, 'preferences')) {
            return response()->json([]);
        }

        $all = $user->preferences()->onlyPublic()->toArray();
        $prefix = $module . '.';

        $filtered = [];
        foreach ($all as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $filtered[substr($key, strlen($prefix))] = $value;
            }
        }

        return response()->json($filtered);
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
