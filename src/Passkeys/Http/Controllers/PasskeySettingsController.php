<?php

namespace Innertia\Passkeys\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Innertia\Passkeys\Support\PasskeyConfig;
use Innertia\Passkeys\Support\PasskeyLog;
use Innertia\Passkeys\Support\PasskeySettings;

class PasskeySettingsController
{
    /** GET {prefix}/passkeys/settings */
    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /** PUT {prefix}/passkeys/settings */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'          => 'required|boolean',
            'allow_subdomains' => 'sometimes|boolean',
        ]);

        PasskeySettings::save($data);
        PasskeyLog::info('passkey.settings_updated', PasskeySettings::all());

        return response()->json($this->payload());
    }

    /** GET público: lo usa el front antes del login (en modo open no hay /status previo). */
    public function status(): JsonResponse
    {
        return response()->json(['enabled' => PasskeyConfig::enabled()]);
    }

    private function payload(): array
    {
        return PasskeySettings::all() + [
            'rp_id'   => PasskeyConfig::rpId(),
            'origins' => PasskeyConfig::origins(),
        ];
    }
}
