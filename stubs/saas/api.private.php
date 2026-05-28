<?php

// stubs/saas/api.private.php — rutas con autenticación requerida (SaaS mode)
// Middleware stack: tenant.resolve → authenticate → tenant.require
// Publicado por innertia-kit via: php artisan vendor:publish --tag=innertia-routes

use Illuminate\Support\Facades\Route;
use App\Apps\Backoffice\Auth\AuthController;
use App\Apps\Backoffice\Users\UsersController;
use App\Apps\Backoffice\Roles\RolesController;
use App\Apps\Backoffice\Permissions\PermissionsController;
use Innertia\Auth\Http\Controllers\SocialSettingsController;
use Innertia\Auth\Http\Controllers\TwoFactorController;
use Innertia\Auth\Middleware\Authenticate;
use Innertia\Notifications\Http\NotificationsController;
use Innertia\Platform\Http\Controllers\SubscriptionController;
use Innertia\Saas\Middleware\RequireTenant;
use Innertia\Saas\Middleware\ResolveTenantFromHeader;

// ── Rutas de sesión — solo requieren autenticación (sin tenant obligatorio) ──
// auth/me, refresh y logout se usan antes/después de seleccionar un tenant.
Route::middleware([ResolveTenantFromHeader::class, Authenticate::class])->group(function () {

    Route::prefix('auth')->group(function () {
        Route::get ('me',             [AuthController::class, 'me']);
        Route::get ('me/permissions', [AuthController::class, 'mePermissions']);
        Route::post('refresh',        [AuthController::class, 'refresh']);
        Route::post('logout',         [AuthController::class, 'logout']);
        Route::post('2fa/enable',     [TwoFactorController::class, 'enable']);
        Route::post('2fa/disable',    [TwoFactorController::class, 'disable']);
    });

});

// ── Rutas de negocio — requieren autenticación + tenant activo ───────────────
Route::middleware([ResolveTenantFromHeader::class, Authenticate::class, RequireTenant::class])->group(function () {

    // ── Suscripciones ─────────────────────────────────────────────────────────
    Route::prefix('subscriptions')->group(function () {
        Route::get   ('/',     [SubscriptionController::class, 'index']);
        Route::post  ('/',     [SubscriptionController::class, 'store']);
        Route::patch ('{id}',  [SubscriptionController::class, 'update']);
        Route::delete('{id}',  [SubscriptionController::class, 'destroy']);
    });

    // ── Notificaciones ────────────────────────────────────────────────────────
    Route::prefix('notifications')->group(function () {
        Route::get   ('/',          [NotificationsController::class, 'index']);
        Route::patch ('/read-all',  [NotificationsController::class, 'markAllRead']);
        Route::patch ('/{id}/read', [NotificationsController::class, 'markRead']);
        Route::delete('/',          [NotificationsController::class, 'destroyRead']);
        Route::delete('/{id}',      [NotificationsController::class, 'destroy']);
    });

    // ── Admin: configuración de proveedores sociales ──────────────────────────
    Route::prefix('admin/auth')->group(function () {
        Route::get('settings',            [SocialSettingsController::class, 'index']);
        Route::get('{provider}/settings', [SocialSettingsController::class, 'show'])
            ->where('provider', 'google|microsoft|github');
        Route::put('{provider}/settings', [SocialSettingsController::class, 'update'])
            ->where('provider', 'google|microsoft|github');
    });

    // ── Backoffice ────────────────────────────────────────────────────────────
    Route::prefix('backoffice')->group(function () {

        // Usuarios
        Route::get   ('users',                     [UsersController::class, 'index']);
        Route::post  ('users',                     [UsersController::class, 'store']);
        Route::get   ('users/{id}',                [UsersController::class, 'show']);
        Route::put   ('users/{id}',                [UsersController::class, 'update']);
        Route::delete('users/{id}',                [UsersController::class, 'destroy']);
        Route::get   ('users/{id}/roles',          [UsersController::class, 'roles']);
        Route::post  ('users/{id}/roles',          [UsersController::class, 'assignRole']);
        Route::delete('users/{id}/roles/{role}',   [UsersController::class, 'removeRole']);
        Route::get   ('users/{id}/contexts',              [UsersController::class, 'contexts']);
        Route::post  ('users/{id}/contexts',              [UsersController::class, 'grantContext']);
        Route::post  ('users/{id}/contexts/sync',         [UsersController::class, 'syncContexts']);
        Route::delete('users/{id}/contexts/{context}',    [UsersController::class, 'revokeContext']);
        Route::get   ('users/{id}/sessions',       [UsersController::class, 'sessions']);
        Route::delete('users/{id}/sessions/{sid}', [UsersController::class, 'revokeSession']);
        Route::delete('users/{id}/sessions',       [UsersController::class, 'revokeAllSessions']);
        Route::post  ('users/{id}/reactivate',     [UsersController::class, 'reactivate']);
        Route::post  ('users/{id}/reset-password', [UsersController::class, 'resetPassword']);
        Route::get   ('users/{id}/activity',       [UsersController::class, 'activity']);

        // Roles
        Route::get   ('roles',                    [RolesController::class, 'index']);
        Route::post  ('roles',                    [RolesController::class, 'store']);
        Route::get   ('roles/{id}',               [RolesController::class, 'show']);
        Route::put   ('roles/{id}',               [RolesController::class, 'update']);
        Route::delete('roles/{id}',               [RolesController::class, 'destroy']);
        Route::post  ('roles/{id}/permissions',   [RolesController::class, 'syncPermissions']);

        // Permisos
        Route::get('permissions', [PermissionsController::class, 'index']);
    });

    // ── Rutas de la aplicación ────────────────────────────────────────────────

});
