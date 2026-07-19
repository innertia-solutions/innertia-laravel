<?php

namespace Innertia\Auth;

use Illuminate\Support\Facades\Route;
use Innertia\Auth\Http\Controllers\AuthController;
use Innertia\Auth\Http\Controllers\EmailVerificationController;
use Innertia\Auth\Http\Controllers\OtpController;
use Innertia\Auth\Http\Controllers\PasswordController;
use Innertia\Auth\Http\Controllers\PlatformAuthController;
use Innertia\Auth\Http\Controllers\SocialAuthController;
use Innertia\Auth\Http\Controllers\TwoFactorController;

/**
 * Helpers opt-in para montar las rutas de autenticación estándar.
 *
 * NO aplican middleware — se llaman DENTRO del grupo del producto, que decide
 * el stack (en saas: ResolveTenantFromHeader; en app: ninguno). Cada producto
 * puede además agregar sus propias rutas en el mismo grupo (extensible) o
 * pasar controllers extendidos por parámetro (customizable).
 *
 *   // routes/api.public.php (saas)
 *   Route::middleware(ResolveTenantFromHeader::class)->group(function () {
 *       \Innertia\Auth\Routes::publicRoutes();              // backoffice/auth/*
 *       // Route::post('backoffice/auth/magic-link', ...)   // extensión del producto
 *   });
 *
 *   // routes/api.private.php
 *   Route::middleware([ResolveTenantFromHeader::class, Authenticate::class])->group(function () {
 *       \Innertia\Auth\Routes::sessionRoutes();             // auth/me, refresh, logout, 2fa...
 *   });
 *
 * Multi-contexto: llamar publicRoutes() una vez por contexto con su prefijo:
 *   \Innertia\Auth\Routes::publicRoutes('technician/auth');
 */
class Routes
{
    /**
     * Rutas públicas de autenticación bajo {$prefix} (default 'backoffice/auth'):
     * login, OTP, 2FA verify, verificación de email, password (forgot/reset/change/set), OAuth.
     */
    public static function publicRoutes(
        string $prefix              = 'auth',
        string $authController      = AuthController::class,
        string $passwordController  = PasswordController::class,
        string $otpController        = OtpController::class,
        string $twoFactorController  = TwoFactorController::class,
        string $emailController      = EmailVerificationController::class,
        string $socialController     = SocialAuthController::class,
        string $oauthProviders       = 'google|microsoft|github',
    ): void {
        Route::prefix($prefix)->group(function () use (
            $authController, $passwordController, $otpController,
            $twoFactorController, $emailController, $socialController, $oauthProviders
        ) {
            Route::post('login',             [$authController, 'login'])->middleware('throttle:10,1');

            Route::post('otp/send',          [$otpController, 'send']);
            Route::post('otp/verify',        [$otpController, 'verify']);

            Route::post('2fa/verify',        [$twoFactorController, 'verify']);

            Route::post('email/verify/send', [$emailController, 'send']);
            Route::get ('email/verify',      [$emailController, 'verify'])->name('auth.email.verify');

            Route::post('password/forgot',   [$passwordController, 'forgot']);
            Route::post('password/reset',    [$passwordController, 'reset']);
            Route::post('password/change',   [$passwordController, 'change']);
            Route::post('password/set',      [$passwordController, 'set']);

            Route::get('{provider}/redirect', [$socialController, 'redirect'])->where('provider', $oauthProviders);
            Route::get('{provider}/callback', [$socialController, 'callback'])->where('provider', $oauthProviders);
        });
    }

    /**
     * Rutas de sesión (requieren auth, NO tenant activo) bajo {$prefix} (default 'auth'):
     * me, me/permissions, refresh, logout, 2fa enable/disable.
     */
    public static function sessionRoutes(
        string $prefix              = 'auth',
        string $authController      = AuthController::class,
        string $twoFactorController = TwoFactorController::class,
    ): void {
        Route::prefix($prefix)->group(function () use ($authController, $twoFactorController) {
            Route::get ('me',             [$authController, 'me']);
            Route::get ('me/permissions', [$authController, 'mePermissions']);
            Route::post('refresh',        [$authController, 'refresh']);
            Route::post('logout',         [$authController, 'logout']);

            Route::post('2fa/enable',     [$twoFactorController, 'enable']);
            Route::post('2fa/disable',    [$twoFactorController, 'disable']);
        });
    }

    /**
     * Ruta pública de auth de plataforma (sin contexto ni tenant).
     * Registrar SIN middleware de resolución de tenant.
     */
    public static function platformRoutes(string $prefix = 'platform'): void
    {
        Route::post($prefix.'/auth/login', [
            PlatformAuthController::class, 'login',
        ])->middleware('throttle:10,1');
    }
}
