<?php

use Illuminate\Support\Facades\Route;
use Innertia\Olimpo\Http\Controllers\OlimpoController;

Route::prefix('olimpo')
    ->middleware('olimpo.auth')
    ->group(function () {
        Route::get('health', [OlimpoController::class, 'health']);

        Route::post('tenants', [OlimpoController::class, 'createTenant']);
        Route::get('tenants/{id}', [OlimpoController::class, 'getTenant']);
        Route::delete('tenants/{id}', [OlimpoController::class, 'deleteTenant']);
        Route::patch('tenants/{id}/suspend', [OlimpoController::class, 'suspendTenant']);
        Route::patch('tenants/{id}/reactivate', [OlimpoController::class, 'reactivateTenant']);
        Route::patch('tenants/{id}/trial', [OlimpoController::class, 'updateTrial']);
        Route::post('tenants/{id}/cache/flush', [OlimpoController::class, 'flushCache']);
        Route::get('tenants/{id}/users', [OlimpoController::class, 'getTenantUsers']);
        Route::post('tenants/{id}/users/{userId}/impersonate', [OlimpoController::class, 'impersonate']);
        Route::get('tenants/{id}/backups', [OlimpoController::class, 'getTenantBackups']);
        Route::post('tenants/{id}/backups', [OlimpoController::class, 'createBackup']);
    });
