<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Auth\Services\JwtService;
use Innertia\Auth\Services\OtpService;
use Innertia\Auth\UseCases\SendOtp;
use Innertia\Auth\UseCases\VerifyOtp;

class OtpController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required',
            'action'  => 'required|string',
        ]);

        $model = config('auth.providers.users.model');
        $user  = $model::findOrFail($data['user_id']);

        (new SendOtp(app(OtpService::class)))->execute($user, $data['action']);

        return response()->json(['message' => 'OTP sent.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required',
            'code'    => 'required|string|size:6',
            'action'  => 'required|string',
        ]);

        $model  = config('auth.providers.users.model');
        $user   = $model::findOrFail($data['user_id']);
        $result = (new VerifyOtp(app(OtpService::class), app(JwtService::class)))
            ->execute($user, $data['code'], $data['action']);

        return response()->json($result);
    }
}
