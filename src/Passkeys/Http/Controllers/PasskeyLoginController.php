<?php

namespace Innertia\Passkeys\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Passkeys\UseCases\BeginPasskeyLogin;
use Innertia\Passkeys\UseCases\PasskeyLogin;
use Innertia\Passkeys\UseCases\PlatformPasskeyLogin;

class PasskeyLoginController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json((new BeginPasskeyLogin())->execute());
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'        => 'required|string',
            'response'   => 'required|array',
            'contexts'   => 'required|array|min:1',
            'contexts.*' => 'string',
        ]);

        return response()->json((new PasskeyLogin($data['key'], $data['response'], $data['contexts']))->execute());
    }

    public function platformLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'      => 'required|string',
            'response' => 'required|array',
        ]);

        return response()->json((new PlatformPasskeyLogin($data['key'], $data['response']))->execute());
    }
}
