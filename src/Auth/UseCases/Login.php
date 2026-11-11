<?php

namespace Innertia\Auth\UseCases;

use Illuminate\Support\Facades\Hash;
use Innertia\Auth\Services\JwtService;
use Innertia\Auth\Services\OtpService;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Exceptions\PlatformAccountException;
use Innertia\Facades\Settings;
use Innertia\Platform\Contracts\UseCase;

class Login extends UseCase
{
    /** Valores de users.otp_mode (null = según la configuración general). */
    public const OTP_ALWAYS = 'always';
    public const OTP_NEVER  = 'never';

    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly string $context,
    ) {}


    public function execute(): array
    {
        // 1 — Credentials
        $model = config('auth.providers.users.model');
        $user  = $model::where('email', $this->email)->first();

        if (! $user || ! Hash::check($this->password, $user->password)) {
            throw new \Illuminate\Validation\ValidationException(
                validator([], []),
                response()->json(['message' => 'Invalid credentials.'], 422)
            );
        }

        return self::issueFor($user, $this->context);
    }

    /**
     * Reglas posteriores a la autenticación primaria, compartidas por el login con
     * contraseña y el de passkey. $strongAuth = el factor ya fue fuerte (passkey con
     * verificación de usuario): no se pide OTP ni 2FA. El cambio forzado de clave y la
     * verificación de correo se mantienen.
     */
    public static function issueFor($user, string $context, bool $strongAuth = false): array
    {
        // 1b — Rechazo de cuentas de plataforma (opt-in). Tras validar la
        // credencial: una credencial incorrecta ya cayó antes (anti-enumeración).
        if (config('innertia.platform.separate_identity') && $user->is_platform_admin) {
            throw new PlatformAccountException();
        }

        // 2 — Context exists in config
        $contexts = config('innertia.contexts', []);

        if (! array_key_exists($context, $contexts)) {
            throw new NotFoundException("Context '{$context}' not found.");
        }

        // 3 — User has access to the context
        if (method_exists($user, 'hasContext') && ! $user->hasContext($context)) {
            throw new ForbiddenException("Access to '{$context}' is not allowed for this user.");
        }

        // 4 — force_password_change
        if ($user->force_password_change) {
            app(OtpService::class)->send($user, 'login');

            return ['requires_password_change' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        // 5 — Email verification required
        if (
            Settings::get('auth.email_verification.enabled', config('innertia.auth.email_verification.enabled', false))
            && ! $user->email_verified_at
        ) {
            return ['requires_email_verification' => true, 'user_id' => $user->getAuthIdentifier()];
        }

        if (! $strongAuth) {
            // 6 — OTP por correo (global con ajuste por usuario; ver otpRequired())
            if (self::otpRequired($user)) {
                app(OtpService::class)->send($user, 'login');

                return ['requires_otp' => true, 'user_id' => $user->getAuthIdentifier()];
            }

            // 7 — 2FA enrolled
            if (
                Settings::get('auth.2fa.enabled', config('innertia.auth.2fa.enabled', false))
                && $user->two_factor_enabled
            ) {
                return ['requires_2fa' => true, 'user_id' => $user->getAuthIdentifier()];
            }
        }

        // 8 — Issue token with context claim
        $token = app(JwtService::class)->generateToken($user, ['context' => $context]);

        return ['token' => $token, 'user' => $user];
    }

    /**
     * ¿Exige código por correo en este ingreso?
     *
     *  - La cuenta demo de QA/dev entra directo (credenciales precargadas, su
     *    casilla no recibe correo).
     *  - users.otp_mode por usuario: 'always' (exige aunque el switch global esté
     *    apagado) / 'never' (eximido) / null (sigue el switch global).
     *  - Switch global: Settings auth.otp.enabled (fallback config).
     *
     * Productos sin la columna otp_mode leen null → solo el switch global.
     */
    public static function otpRequired($user): bool
    {
        if (self::isDemoAccount($user)) {
            return false;
        }

        return match ($user->otp_mode ?? null) {
            self::OTP_ALWAYS => true,
            self::OTP_NEVER  => false,
            default          => (bool) Settings::get('auth.otp.enabled', config('innertia.auth.otp.enabled', false)),
        };
    }

    /**
     * Cuenta demo del modo demo (innertia.demo.*: credenciales precargadas en el
     * login de QA/dev). Nunca en producción.
     */
    private static function isDemoAccount($user): bool
    {
        if (app()->environment('production') || ! config('innertia.demo.enabled')) {
            return false;
        }

        $demoEmail = (string) config('innertia.demo.email', '');

        return $demoEmail !== '' && strcasecmp($demoEmail, (string) $user->email) === 0;
    }
}
