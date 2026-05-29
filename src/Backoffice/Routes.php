<?php

namespace Innertia\Backoffice;

use Illuminate\Support\Facades\Route;
use Innertia\Backoffice\Http\Controllers\PermissionsController;
use Innertia\Backoffice\Http\Controllers\RolesController;
use Innertia\Backoffice\Http\Controllers\SessionsController;
use Innertia\Backoffice\Http\Controllers\UsersController;

/**
 * Helper opt-in para montar TODO el backoffice genérico en una línea:
 * usuarios (+ roles, contextos, sesiones, reactivar, reset-password, activity),
 * roles, permisos y gestión global de sesiones.
 *
 * NO aplica middleware ni prefijo de tenant — se llama DENTRO del grupo de
 * middleware del producto (igual que Organizations\Routes / Teams\Routes):
 *
 *   // routes/api.private.php
 *   Route::middleware([ResolveTenantFromHeader::class, Authenticate::class, RequireTenant::class])
 *       ->group(function () {
 *           \Innertia\Backoffice\Routes::register();              // users/roles/permissions/sessions
 *           \Innertia\Platform\Organizations\Routes::register('backoffice/organizations');
 *           \Innertia\Platform\Teams\Routes::register('backoffice/teams');
 *       });
 *
 * Para customizar un controller, extiéndelo y pásalo por parámetro; o no
 * llames register() y define tus propias rutas.
 */
class Routes
{
    public static function register(
        string $prefix             = 'backoffice',
        string $usersController    = UsersController::class,
        string $rolesController    = RolesController::class,
        string $permsController    = PermissionsController::class,
        string $sessionsController = SessionsController::class,
    ): void {
        Route::prefix($prefix)->group(function () use (
            $usersController, $rolesController, $permsController, $sessionsController
        ) {
            // ── Usuarios ───────────────────────────────────────────────────────
            Route::get   ('users',                          [$usersController, 'index']);
            Route::post  ('users',                          [$usersController, 'store']);
            Route::get   ('users/{id}',                     [$usersController, 'show']);
            Route::put   ('users/{id}',                     [$usersController, 'update']);
            Route::delete('users/{id}',                     [$usersController, 'destroy']);
            Route::get   ('users/{id}/roles',               [$usersController, 'roles']);
            Route::post  ('users/{id}/roles',               [$usersController, 'assignRole']);
            Route::delete('users/{id}/roles/{role}',        [$usersController, 'removeRole']);
            Route::get   ('users/{id}/contexts',            [$usersController, 'contexts']);
            Route::post  ('users/{id}/contexts',            [$usersController, 'grantContext']);
            Route::post  ('users/{id}/contexts/sync',       [$usersController, 'syncContexts']);
            Route::delete('users/{id}/contexts/{context}',  [$usersController, 'revokeContext']);
            Route::get   ('users/{id}/sessions',            [$usersController, 'sessions']);
            Route::delete('users/{id}/sessions/{sid}',      [$usersController, 'revokeSession']);
            Route::delete('users/{id}/sessions',            [$usersController, 'revokeAllSessions']);
            Route::post  ('users/{id}/reactivate',          [$usersController, 'reactivate']);
            Route::post  ('users/{id}/reset-password',      [$usersController, 'resetPassword']);
            Route::get   ('users/{id}/activity',            [$usersController, 'activity']);

            // ── Roles ──────────────────────────────────────────────────────────
            Route::get   ('roles',                          [$rolesController, 'index']);
            Route::post  ('roles',                          [$rolesController, 'store']);
            Route::get   ('roles/{id}',                     [$rolesController, 'show']);
            Route::put   ('roles/{id}',                     [$rolesController, 'update']);
            Route::delete('roles/{id}',                     [$rolesController, 'destroy']);
            Route::post  ('roles/{id}/permissions',         [$rolesController, 'syncPermissions']);

            // ── Permisos ─────────────────────────────────────────────────────────
            Route::get   ('permissions',                    [$permsController, 'index']);

            // ── Sesiones (admin global de sesiones JWT activas) ──────────────────
            Route::get   ('sessions',                       [$sessionsController, 'index']);
            Route::delete('sessions/{id}',                  [$sessionsController, 'destroy']);
            Route::delete('sessions',                       [$sessionsController, 'destroyAll']);
        });
    }
}
