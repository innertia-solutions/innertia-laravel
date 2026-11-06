<?php

use Innertia\Auth\UseCases\Login;

/**
 * Código por correo por usuario (users.otp_mode): 'always' exige aunque el
 * switch global esté apagado, 'never' exime aunque esté encendido, null sigue
 * el switch. La cuenta demo (fuera de prod) siempre entra directo.
 */
function otpUser(?string $mode, string $email = 'u@x.cl'): object
{
    return (object) ['otp_mode' => $mode, 'email' => $email];
}

it('exige el código al usuario marcado como siempre', function () {
    expect(Login::otpRequired(otpUser(Login::OTP_ALWAYS)))->toBeTrue();
});

it('exime al usuario marcado como nunca', function () {
    expect(Login::otpRequired(otpUser(Login::OTP_NEVER)))->toBeFalse();
});

it('la cuenta demo entra directo aunque esté marcada como siempre', function () {
    config()->set('innertia.demo.enabled', true);
    config()->set('innertia.demo.email', 'demo@demo.com');

    expect(Login::otpRequired(otpUser(Login::OTP_ALWAYS, 'DEMO@demo.com')))->toBeFalse();
});
