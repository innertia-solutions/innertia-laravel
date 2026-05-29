<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Innertia\Auth\UseCases\ChangePassword;
use Innertia\Auth\UseCases\SetPassword;

class PasswordController extends Controller
{
    /**
     * POST /backoffice/auth/password/forgot
     *
     * Envía el email con el link de recuperación. Responde siempre 200 para
     * no revelar si el correo existe (anti-enumeración).
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'Si el correo existe, recibirás las instrucciones en breve.',
        ]);
    }

    /**
     * POST /backoffice/auth/password/reset
     *
     * Restablece la contraseña usando el token enviado por email.
     */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'                 => 'required|string',
            'email'                 => 'required|email',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $status = Password::reset(
            $data,
            function ($user, string $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Contraseña restablecida correctamente.']);
        }

        return response()->json(['message' => __($status)], 422);
    }

    public function change(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'               => 'required',
            'password'              => 'required|string|min:8',
            'password_confirmation' => 'required|string',
            'context'               => 'required|string',
        ]);

        $result = (new ChangePassword(
            userId:               $data['user_id'],
            password:             $data['password'],
            passwordConfirmation: $data['password_confirmation'],
            context:              $data['context'],
        ))->execute();

        return response()->json($result);
    }

    public function set(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'               => 'required',
            'password'              => 'required|string|min:8',
            'password_confirmation' => 'required|string',
            'context'               => 'required|string',
        ]);

        $result = (new SetPassword(
            userId:               $data['user_id'],
            password:             $data['password'],
            passwordConfirmation: $data['password_confirmation'],
            context:              $data['context'],
        ))->execute();

        return response()->json($result);
    }
}
