<?php

namespace Innertia\Auth\EmailChange;

use Illuminate\Support\Facades\Route;
use Innertia\Auth\EmailChange\Http\EmailChangeController;

/** Email change for the authenticated user. Register inside an authenticated group. */
class Routes
{
    public static function account(string $prefix = 'me/email-change'): void
    {
        $prefix = trim($prefix, '/');

        Route::post($prefix,              [EmailChangeController::class, 'request'])->middleware('throttle:5,10,email-change-request');
        Route::post($prefix . '/confirm', [EmailChangeController::class, 'confirm'])->middleware('throttle:10,1,email-change-confirm');
    }
}
