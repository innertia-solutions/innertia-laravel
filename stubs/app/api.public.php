<?php

// stubs/app/api.public.php — rutas sin autenticación (App mode)
// Publicado por innertia-kit via: php artisan vendor:publish --tag=innertia-routes

use Illuminate\Support\Facades\Route;
use App\Apps\Backoffice\Auth\AuthController;
use App\Apps\Backoffice\Auth\PasswordController;
use Innertia\Auth\Http\Controllers\EmailVerificationController;
use Innertia\Auth\Http\Controllers\OtpController;
use Innertia\Auth\Http\Controllers\SocialAuthController;
use Innertia\Auth\Http\Controllers\TwoFactorController;

Route::prefix('auth')->group(function () {

    Route::post('login',             [AuthController::class, 'login']);
    Route::post('otp/send',          [OtpController::class, 'send']);
    Route::post('otp/verify',        [OtpController::class, 'verify']);
    Route::post('2fa/verify',        [TwoFactorController::class, 'verify']);
    Route::post('email/verify/send', [EmailVerificationController::class, 'send']);
    Route::get ('email/verify',      [EmailVerificationController::class, 'verify'])->name('auth.email.verify');
    Route::post('password/change',   [PasswordController::class, 'change']);
    Route::post('password/set',      [PasswordController::class, 'set']);

    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->where('provider', 'google|microsoft|github');
    Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])
        ->where('provider', 'google|microsoft|github');
});
