<?php

use Illuminate\Support\Facades\Route;
use Innertia\Auth\Http\Controllers\AuthController;
use Innertia\Auth\Http\Controllers\EmailVerificationController;
use Innertia\Auth\Http\Controllers\OtpController;
use Innertia\Auth\Http\Controllers\PasswordController;
use Innertia\Auth\Http\Controllers\SocialAuthController;
use Innertia\Auth\Http\Controllers\SocialSettingsController;
use Innertia\Auth\Http\Controllers\TwoFactorController;
use Innertia\Auth\Middleware\Authenticate;
use Innertia\Exports\ExportController;
use Innertia\Platform\Http\Controllers\SubscriptionController;

Route::prefix('auth')->group(function () {

    // Public
    Route::post('login',                [AuthController::class, 'login']);
    Route::post('otp/send',             [OtpController::class, 'send']);
    Route::post('otp/verify',           [OtpController::class, 'verify']);
    Route::post('2fa/verify',           [TwoFactorController::class, 'verify']);
    Route::post('email/verify/send',    [EmailVerificationController::class, 'send']);
    Route::get('email/verify',          [EmailVerificationController::class, 'verify'])->name('auth.email.verify');
    Route::post('password/change',      [PasswordController::class, 'change']);
    Route::post('password/set',         [PasswordController::class, 'set']);

    // Social login — redirect & callback
    Route::get('{provider}/redirect',   [SocialAuthController::class, 'redirect'])
        ->where('provider', 'google|microsoft|github');
    Route::get('{provider}/callback',   [SocialAuthController::class, 'callback'])
        ->where('provider', 'google|microsoft|github');

    // Protected
    Route::middleware(Authenticate::class)->group(function () {
        Route::get('me',                [AuthController::class, 'me']);
        Route::post('refresh',          [AuthController::class, 'refresh']);
        Route::post('logout',           [AuthController::class, 'logout']);
        Route::post('2fa/enable',       [TwoFactorController::class, 'enable']);
        Route::post('2fa/disable',      [TwoFactorController::class, 'disable']);
    });

});

// Exports — tenant data exports (compliance / GDPR)
Route::middleware(Authenticate::class)->prefix('exports')->group(function () {
    Route::get('/',      [ExportController::class, 'index']);
    Route::get('{id}',   [ExportController::class, 'show']);
});

// Subscriptions — authenticated user manages their own subscriptions
Route::middleware(Authenticate::class)->prefix('subscriptions')->group(function () {
    Route::get('/',         [SubscriptionController::class, 'index']);
    Route::post('/',        [SubscriptionController::class, 'store']);
    Route::patch('{id}',    [SubscriptionController::class, 'update']);
    Route::delete('{id}',   [SubscriptionController::class, 'destroy']);
});

// Admin: social provider settings (protected)
Route::middleware(Authenticate::class)->prefix('admin/auth')->group(function () {
    Route::get('settings',              [SocialSettingsController::class, 'index']);
    Route::get('{provider}/settings',   [SocialSettingsController::class, 'show'])
        ->where('provider', 'google|microsoft|github');
    Route::put('{provider}/settings',   [SocialSettingsController::class, 'update'])
        ->where('provider', 'google|microsoft|github');
});
