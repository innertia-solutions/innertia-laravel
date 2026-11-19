<?php

namespace Innertia\Mail;

use Illuminate\Support\Facades\Route;
use Innertia\Mail\Http\Controllers\EmailsController;
use Innertia\Mail\Http\Controllers\MailSettingsController;
use Innertia\Mail\Http\Controllers\TenantBrandingController;

/**
 * Opt-in route registrars (no auth of their own: register them INSIDE the product's
 * authenticated groups).
 *
 *   // platform group (auth + platform guard)
 *   \Innertia\Mail\Routes::platform('');            // emails (+types) for owner and staff,
 *                                                   // mail-settings only for the owner
 *
 *   // backoffice group (auth + tenant + settings.manage)
 *   \Innertia\Mail\Routes::tenantBranding('settings/branding');
 */
class Routes
{
    /**
     * @param array $settingsMiddleware middleware for mail-settings (default: owner only).
     */
    public static function platform(string $prefix = '', array $settingsMiddleware = ['platform.owner']): void
    {
        $p = fn (string $path) => ltrim(rtrim($prefix, '/') . '/' . $path, '/');

        Route::get($p('emails'), [EmailsController::class, 'index']);
        Route::get($p('emails/types'), [EmailsController::class, 'types']);

        Route::middleware($settingsMiddleware)->group(function () use ($p) {
            Route::get   ($p('mail-settings'),            [MailSettingsController::class, 'show']);
            Route::put   ($p('mail-settings'),            [MailSettingsController::class, 'update']);
            Route::delete($p('mail-settings/resend-key'), [MailSettingsController::class, 'clearResendKey']);
            Route::post  ($p('mail-settings/test'),       [MailSettingsController::class, 'test'])->middleware('throttle:10,1,mail-settings-test');
            Route::get   ($p('mail-settings/preview'),    [MailSettingsController::class, 'preview']);
        });
    }

    public static function tenantBranding(string $prefix = 'settings/branding'): void
    {
        $prefix = trim($prefix, '/');

        Route::get ($prefix,           [TenantBrandingController::class, 'show']);
        Route::put ($prefix,           [TenantBrandingController::class, 'update']);
        Route::post($prefix . '/test', [TenantBrandingController::class, 'test'])->middleware('throttle:5,1,tenant-branding-test');
    }
}
