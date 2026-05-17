<?php

use Illuminate\Support\Facades\Route;
use Innertia\Auth\Middleware\Authenticate;
use Innertia\ApiKeys\Http\Controllers\ApiKeysController;
use Innertia\Backoffice\Http\Controllers\PermissionsController;
use Innertia\Backoffice\Http\Controllers\RolesController;
use Innertia\Backoffice\Http\Controllers\UsersController;

$prefix     = config('innertia.backoffice.prefix', 'backoffice');
$extra      = config('innertia.backoffice.middleware', []);
$middleware = array_merge([Authenticate::class], (array) $extra);

Route::prefix($prefix)
    ->middleware($middleware)
    ->group(function () {

        // ── Users ─────────────────────────────────────────────────────────────
        Route::get    ('users',                        [UsersController::class, 'index']);
        Route::post   ('users',                        [UsersController::class, 'store']);
        Route::get    ('users/{id}',                   [UsersController::class, 'show']);
        Route::put    ('users/{id}',                   [UsersController::class, 'update']);
        Route::delete ('users/{id}',                   [UsersController::class, 'destroy']);
        Route::get    ('users/{id}/roles',             [UsersController::class, 'roles']);
        Route::post   ('users/{id}/roles',             [UsersController::class, 'assignRole']);
        Route::delete ('users/{id}/roles/{role}',      [UsersController::class, 'removeRole']);
        Route::get    ('users/{id}/apps',              [UsersController::class, 'apps']);
        Route::post   ('users/{id}/apps',              [UsersController::class, 'grantApp']);
        Route::post   ('users/{id}/apps/sync',         [UsersController::class, 'syncApps']);
        Route::delete ('users/{id}/apps/{app}',        [UsersController::class, 'revokeApp']);

        // ── Sessions ──────────────────────────────────────────────────────────
        Route::get    ('users/{id}/sessions',              [UsersController::class, 'sessions']);
        Route::delete ('users/{id}/sessions/{sessionId}',  [UsersController::class, 'revokeSession']);
        Route::delete ('users/{id}/sessions',              [UsersController::class, 'revokeAllSessions']);

        // ── Password + Reactivate ─────────────────────────────────────────────
        Route::post   ('users/{id}/reactivate',            [UsersController::class, 'reactivate']);
        Route::post   ('users/{id}/reset-password',        [UsersController::class, 'resetPassword']);

        // ── Activity ──────────────────────────────────────────────────────────
        Route::get    ('users/{id}/activity',              [UsersController::class, 'activity']);

        // ── Roles ─────────────────────────────────────────────────────────────
        Route::get    ('roles',                        [RolesController::class, 'index']);
        Route::post   ('roles',                        [RolesController::class, 'store']);
        Route::get    ('roles/{id}',                   [RolesController::class, 'show']);
        Route::put    ('roles/{id}',                   [RolesController::class, 'update']);
        Route::delete ('roles/{id}',                   [RolesController::class, 'destroy']);
        Route::post   ('roles/{id}/permissions',       [RolesController::class, 'syncPermissions']);

        // ── Permissions ───────────────────────────────────────────────────────
        Route::get    ('permissions',                  [PermissionsController::class, 'index']);

        // ── API Keys — Tenant ─────────────────────────────────────────────────
        Route::get    ('api-keys',                     [ApiKeysController::class, 'index']);
        Route::post   ('api-keys',                     [ApiKeysController::class, 'store']);
        Route::delete ('api-keys/{id}',                [ApiKeysController::class, 'destroy']);
        Route::get    ('api-keys/permissions',         [ApiKeysController::class, 'permissions']);

        // ── API Keys — User (self-service) ────────────────────────────────────
        Route::get    ('api-keys/user',                [ApiKeysController::class, 'userIndex']);
        Route::post   ('api-keys/user',                [ApiKeysController::class, 'userStore']);
        Route::delete ('api-keys/user/{id}',           [ApiKeysController::class, 'userDestroy']);
    });
