<?php

use Illuminate\Support\Facades\Route;
use Innertia\Auth\Http\Controllers\AuthController;
use Innertia\Auth\Http\Controllers\EmailVerificationController;
use Innertia\Auth\Http\Controllers\OtpController;
use Innertia\Auth\Http\Controllers\PasswordController;
use Innertia\Auth\Http\Controllers\TwoFactorController;
use Innertia\Auth\Middleware\Authenticate;

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

    // Protected
    Route::middleware(Authenticate::class)->group(function () {
        Route::get('me',                [AuthController::class, 'me']);
        Route::post('refresh',          [AuthController::class, 'refresh']);
        Route::post('logout',           [AuthController::class, 'logout']);
        Route::post('2fa/enable',       [TwoFactorController::class, 'enable']);
        Route::post('2fa/disable',      [TwoFactorController::class, 'disable']);
    });

});
