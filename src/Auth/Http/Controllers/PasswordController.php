<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Auth\UseCases\ChangePassword;
use Innertia\Auth\UseCases\SetPassword;

class PasswordController extends Controller
{
    public function change(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'               => 'required',
            'password'              => 'required|string|min:8',
            'password_confirmation' => 'required|string',
            'app'                   => 'required|string',
        ]);

        $result = (new ChangePassword(
            userId:               $data['user_id'],
            password:             $data['password'],
            passwordConfirmation: $data['password_confirmation'],
            app:                  $data['app'],
        ))->execute();

        return response()->json($result);
    }

    public function set(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'               => 'required',
            'password'              => 'required|string|min:8',
            'password_confirmation' => 'required|string',
            'app'                   => 'required|string',
        ]);

        $result = (new SetPassword(
            userId:               $data['user_id'],
            password:             $data['password'],
            passwordConfirmation: $data['password_confirmation'],
            app:                  $data['app'],
        ))->execute();

        return response()->json($result);
    }
}
