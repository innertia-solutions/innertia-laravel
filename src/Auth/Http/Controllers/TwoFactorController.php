<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Auth\Services\JwtService;
use Innertia\Auth\UseCases\Disable2FA;
use Innertia\Auth\UseCases\Enable2FA;
use Innertia\Auth\UseCases\Verify2FA;

class TwoFactorController extends Controller
{
    public function enable(Request $request): JsonResponse
    {
        $result = (new Enable2FA($request->user()))->execute();

        return response()->json($result);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required',
            'code'    => 'required|string',
        ]);

        $model  = config('auth.providers.users.model');
        $user   = $model::findOrFail($data['user_id']);
        $result = (new Verify2FA(app(JwtService::class), $user, $data['code']))->execute();

        return response()->json($result);
    }

    public function disable(Request $request): JsonResponse
    {
        (new Disable2FA($request->user()))->execute();

        return response()->json(['message' => '2FA disabled.']);
    }
}
