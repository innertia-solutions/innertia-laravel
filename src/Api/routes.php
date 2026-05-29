<?php
use Illuminate\Support\Facades\Route;
use Innertia\Api\Http\Controllers\ApiKeysController;
use Innertia\Api\Http\Controllers\OrganizationsController;

Route::prefix('olimpo')
    ->middleware('olimpo.auth')
    ->group(function () {
        // Organizations
        Route::get   ('organizations',                          [OrganizationsController::class, 'index']);
        Route::post  ('organizations',                          [OrganizationsController::class, 'store']);
        Route::get   ('organizations/{organization}',           [OrganizationsController::class, 'show']);
        Route::post  ('organizations/{organization}/children',  [OrganizationsController::class, 'storeChild']);
        Route::patch ('organizations/{organization}/suspend',   [OrganizationsController::class, 'suspend']);
        Route::patch ('organizations/{organization}/reactivate',[OrganizationsController::class, 'reactivate']);
        Route::delete('organizations/{organization}',           [OrganizationsController::class, 'destroy']);

        // API Keys
        Route::get   ('organizations/{organization}/api-keys',           [ApiKeysController::class, 'index']);
        Route::post  ('organizations/{organization}/api-keys',           [ApiKeysController::class, 'store']);
        Route::delete('organizations/{organization}/api-keys/{apiKey}',  [ApiKeysController::class, 'revoke']);
    });
