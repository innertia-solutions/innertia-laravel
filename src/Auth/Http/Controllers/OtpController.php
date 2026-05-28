<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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

        (new SendOtp(
            userId: $data['user_id'],
            action: $data['action'],
        ))->execute();

        return response()->json(['message' => 'OTP sent.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required',
            'code'    => 'required|string|size:6',
            'action'  => 'required|string',
            'context' => 'required|string',
        ]);

        $result = (new VerifyOtp(
            userId: $data['user_id'],
            code:   $data['code'],
            action: $data['action'],
            context: $data['context'],
        ))->execute();

        return response()->json($result);
    }
}
