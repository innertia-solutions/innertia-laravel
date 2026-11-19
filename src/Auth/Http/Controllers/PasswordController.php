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

        // Invitation links (own broker, TTL in days) land on the same set-password page.
        $invitation = \Innertia\Auth\Invitations\InvitationBroker::check($data['email'], $data['token']);

        if ($invitation === \Innertia\Auth\Invitations\InvitationBroker::EXPIRED) {
            return response()->json([
                'message' => 'El link venció, pide uno nuevo.',
                'code'    => 'invitation_expired',
            ], 422);
        }

        if ($invitation === \Innertia\Auth\Invitations\InvitationBroker::VALID) {
            return $this->acceptInvitation($data);
        }

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

    /** Sets the password of an invited user, consumes the token and verifies the email. */
    protected function acceptInvitation(array $data): JsonResponse
    {
        $model = config('innertia.auth.user_model', config('auth.providers.users.model'));
        $userId = \Innertia\Auth\Invitations\InvitationBroker::userIdFor($data['email']);
        $user = $userId ? $model::query()->find($userId) : null;

        if (! $user) {
            return response()->json(['message' => __(Password::INVALID_TOKEN)], 422);
        }

        $user->forceFill([
            'password'       => Hash::make($data['password']),
            'remember_token' => Str::random(60),
        ]);

        if (\Illuminate\Support\Facades\Schema::hasColumn($user->getTable(), 'email_verified_at') && ! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()]);
        }

        $user->save();
        \Innertia\Auth\Invitations\InvitationBroker::consume($data['email']);

        event(new PasswordReset($user));

        return response()->json(['message' => 'Contraseña creada correctamente.']);
    }

    public function change(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'               => 'required',
            'password'              => 'required|string|min:8',
            'password_confirmation' => 'required|string',
            'context'               => 'required|string',
            'code'                  => 'nullable|string|size:6',
        ]);

        $result = (new ChangePassword(
            userId:               $data['user_id'],
            password:             $data['password'],
            passwordConfirmation: $data['password_confirmation'],
            context:              $data['context'],
            code:                 $data['code'] ?? null,
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
