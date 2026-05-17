<?php

use Illuminate\Support\Facades\Route;
use Innertia\Api\Http\Controllers\ClientsController;
use Innertia\Api\Http\Controllers\ClientApiKeysController;

Route::prefix('olimpo')
    ->middleware('olimpo.auth')
    ->group(function () {

        // ── Clients ───────────────────────────────────────────────────────────
        Route::get   ('clients',              [ClientsController::class, 'index']);
        Route::post  ('clients',              [ClientsController::class, 'store']);
        Route::get   ('clients/{id}',         [ClientsController::class, 'show']);
        Route::patch ('clients/{id}/suspend',    [ClientsController::class, 'suspend']);
        Route::patch ('clients/{id}/reactivate', [ClientsController::class, 'reactivate']);
        Route::delete('clients/{id}',         [ClientsController::class, 'destroy']);

        // ── API Keys por client ───────────────────────────────────────────────
        Route::get   ('api-keys/permissions',          [ClientApiKeysController::class, 'permissions']);
        Route::get   ('clients/{id}/api-keys',         [ClientApiKeysController::class, 'index']);
        Route::post  ('clients/{id}/api-keys',         [ClientApiKeysController::class, 'store']);
        Route::delete('clients/{id}/api-keys/{keyId}', [ClientApiKeysController::class, 'revoke']);
    });
