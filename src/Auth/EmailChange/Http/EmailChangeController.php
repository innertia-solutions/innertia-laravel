<?php

namespace Innertia\Auth\EmailChange\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Auth\EmailChange\UseCases\ConfirmEmailChange;
use Innertia\Auth\EmailChange\UseCases\RequestEmailChange;

/**
 *   POST me/email-change          {email, current_password}  → code to the new address
 *   POST me/email-change/confirm  {code}                     → email updated
 */
class EmailChangeController extends Controller
{
    public function request(Request $request): array
    {
        $requirePassword = (bool) config('innertia.auth.email_change_requires_password', true);

        $data = $request->validate([
            'email'            => 'required|email|max:191',
            'current_password' => ($requirePassword ? 'required' : 'nullable') . '|string|max:200',
        ], [], ['email' => 'correo', 'current_password' => 'contraseña actual']);

        return (new RequestEmailChange(
            (string) $request->user()->getAuthIdentifier(),
            $data['email'],
            $data['current_password'] ?? null,
            $requirePassword,
        ))->execute();
    }

    public function confirm(Request $request): array
    {
        $data = $request->validate(['code' => 'required|string|size:6'], [], ['code' => 'código']);

        return (new ConfirmEmailChange((string) $request->user()->getAuthIdentifier(), $data['code']))->execute();
    }
}
