<?php

use Illuminate\Support\Facades\Route;
use Innertia\Auth\Http\Controllers\AuthController;
use Innertia\Auth\Http\Controllers\OtpController;
use Innertia\Auth\Http\Controllers\TwoFactorController;
use Innertia\Auth\Middleware\Authenticate;

Route::prefix('auth')->group(function () {

    // Public
    Route::post('login',          [AuthController::class, 'login']);
    Route::post('otp/send',       [OtpController::class, 'send']);
    Route::post('otp/verify',     [OtpController::class, 'verify']);
    Route::post('2fa/verify',     [TwoFactorController::class, 'verify']);

    // Protected
    Route::middleware(Authenticate::class)->group(function () {
        Route::get('me',          [AuthController::class, 'me']);
        Route::post('refresh',    [AuthController::class, 'refresh']);
        Route::post('logout',     [AuthController::class, 'logout']);
        Route::post('2fa/enable', [TwoFactorController::class, 'enable']);
        Route::post('2fa/disable',[TwoFactorController::class, 'disable']);
    });

});
