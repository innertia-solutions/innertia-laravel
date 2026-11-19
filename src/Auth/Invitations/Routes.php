<?php

namespace Innertia\Auth\Invitations;

use Illuminate\Support\Facades\Route;
use Innertia\Auth\Invitations\Http\InvitationsController;

/**
 * Invitation status + resend for user administration. Register inside the product's
 * authenticated group with its permission middleware:
 *
 *   Route::middleware('permission:users.manage')->group(fn () => \Innertia\Auth\Invitations\Routes::admin('users'));
 */
class Routes
{
    public static function admin(string $prefix = 'users', string $controller = InvitationsController::class): void
    {
        $prefix = trim($prefix, '/');

        Route::get ($prefix . '/{id}/invitation', [$controller, 'show']);
        Route::post($prefix . '/{id}/invitation', [$controller, 'resend'])->middleware('throttle:20,1,invitation-resend');
    }
}
