<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Auth\Social\SocialProvider;
use Innertia\Facades\Settings;

class SocialSettingsController extends Controller
{
    /**
     * GET /admin/auth/{provider}/settings
     *
     * Returns the current configuration for a provider.
     * The client_secret is never returned.
     */
    public function show(string $provider): JsonResponse
    {
        $p   = SocialProvider::from($provider);
        $key = $p->value;

        return response()->json([
            'provider'         => $key,
            'label'            => $p->label(),
            'enabled'          => (bool) Settings::get("auth.{$key}.enabled", false),
            'client_id'        => Settings::get("auth.{$key}.client_id"),
            'client_secret_set' => (bool) Settings::get("auth.{$key}.client_secret"),
        ]);
    }

    /**
     * GET /admin/auth/settings
     *
     * Returns the configuration for all providers at once.
     */
    public function index(): JsonResponse
    {
        $result = [];

        foreach (SocialProvider::cases() as $provider) {
            $key = $provider->value;

            $result[$key] = [
                'provider'          => $key,
                'label'             => $provider->label(),
                'enabled'           => (bool) Settings::get("auth.{$key}.enabled", false),
                'client_id'         => Settings::get("auth.{$key}.client_id"),
                'client_secret_set' => (bool) Settings::get("auth.{$key}.client_secret"),
            ];
        }

        return response()->json($result);
    }

    /**
     * PUT /admin/auth/{provider}/settings
     *
     * Update credentials for a provider.
     * Only fields present in the request are updated.
     */
    public function update(Request $request, string $provider): JsonResponse
    {
        $p   = SocialProvider::from($provider);
        $key = $p->value;

        $data = $request->validate([
            'enabled'       => 'sometimes|boolean',
            'client_id'     => 'sometimes|string|max:500',
            'client_secret' => 'sometimes|string|max:500',
        ]);

        foreach ($data as $field => $value) {
            Settings::set("auth.{$key}.{$field}", $value);
        }

        return response()->json([
            'message'  => "{$p->label()} settings updated.",
            'provider' => $key,
            'enabled'  => (bool) Settings::get("auth.{$key}.enabled", false),
        ]);
    }
}
