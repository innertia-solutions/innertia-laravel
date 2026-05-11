<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Auth\UseCases\Login;
use Innertia\Auth\UseCases\Logout;
use Innertia\Auth\UseCases\RefreshToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'app'      => 'required|string',
        ]);

        $result = (new Login(
            email:    $data['email'],
            password: $data['password'],
            app:      $data['app'],
        ))->execute();

        return response()->json($result);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function refresh(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);
        $new   = (new RefreshToken(app(\Innertia\Auth\Services\JwtService::class)))->execute($token);

        return response()->json(['token' => $new]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);
        (new Logout(app(\Innertia\Auth\Services\JwtService::class)))->execute($token);

        return response()->json(['message' => 'Logged out.']);
    }

    protected function extractToken(Request $request): string
    {
        $header = $request->header('Authorization', '');
        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    }
}
