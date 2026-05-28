<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Auth\UseCases\SendEmailVerification;
use Innertia\Auth\UseCases\VerifyEmail;

class EmailVerificationController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required',
        ]);

        (new SendEmailVerification(userId: $data['user_id']))->execute();

        return response()->json(['message' => 'Verification email sent.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required',
            'context' => 'required|string',
        ]);

        if (! $request->hasValidSignature()) {
            return response()->json(['message' => 'Invalid or expired verification link.'], 403);
        }

        $result = (new VerifyEmail(
            userId: $data['user_id'],
            context: $data['context'],
        ))->execute();

        return response()->json($result);
    }
}
