<?php

namespace Innertia\PublicApi;

use Illuminate\Support\Facades\Route;
use Innertia\Notifications\NoticeTypes;
use Innertia\PublicApi\Http\Controllers\DevelopersController;
use Innertia\PublicApi\Http\Controllers\IntegrationKeysController;
use Innertia\PublicApi\Http\Controllers\OpenApiController;
use Innertia\PublicApi\Http\Controllers\WebhooksController;

/**
 * Routes of the public API engine. The product decides where and under which middleware:
 *
 *   // routes/api.private.php — management (UI components Integrations.ApiKeys / Integrations.Webhooks)
 *   Route::middleware(['auth:api', 'tenant.require', 'permission:settings.manage'])->group(function () {
 *       \Innertia\PublicApi\Routes::manage();            // /integrations/api-keys…, /integrations/webhooks…
 *   });
 *
 *   // routes/api.php — public documentation
 *   \Innertia\PublicApi\Routes::docs();                  // GET /v1/openapi.json
 *   \Innertia\PublicApi\Routes::developers();            // GET /developers (Scalar)
 *
 *   // routes/api.clients.php — your v1 endpoints
 *   Route::prefix('v1')->middleware('api.key')->group(function () {
 *       Route::get('calls', [CallsController::class, 'index'])->apiDoc(['summary' => 'Lista llamadas', 'scope' => 'calls:read', 'paginated' => true]);
 *   });
 */
final class Routes
{
    public static function manage(string $prefix = 'integrations'): void
    {
        // Products that adopt the engine get the `webhook.disabled` notice in their preferences.
        if (NoticeTypes::get(NoticeTypes::WEBHOOK_DISABLED) === null) {
            NoticeTypes::register(...NoticeTypes::integrationTypes());
        }

        Route::prefix($prefix)->group(function () {
            Route::get('api-keys', [IntegrationKeysController::class, 'index']);
            Route::get('api-keys/scopes', [IntegrationKeysController::class, 'scopes']);
            Route::post('api-keys', [IntegrationKeysController::class, 'store']);
            Route::post('api-keys/{id}/rotate', [IntegrationKeysController::class, 'rotate']);
            Route::delete('api-keys/{id}', [IntegrationKeysController::class, 'destroy']);
            Route::get('api-keys/{id}/requests', [IntegrationKeysController::class, 'requests']);

            Route::get('webhooks', [WebhooksController::class, 'index']);
            Route::get('webhooks/events', [WebhooksController::class, 'events']);
            Route::post('webhooks', [WebhooksController::class, 'store']);
            Route::get('webhooks/{id}', [WebhooksController::class, 'show'])->whereNumber('id');
            Route::patch('webhooks/{id}', [WebhooksController::class, 'update'])->whereNumber('id');
            Route::delete('webhooks/{id}', [WebhooksController::class, 'destroy'])->whereNumber('id');
            Route::post('webhooks/{id}/rotate-secret', [WebhooksController::class, 'rotateSecret'])->whereNumber('id');
            Route::post('webhooks/{id}/test', [WebhooksController::class, 'test'])->whereNumber('id');
            Route::get('webhooks/{id}/logs', [WebhooksController::class, 'logs'])->whereNumber('id');
            Route::get('webhooks/{id}/logs/{log}', [WebhooksController::class, 'log'])->whereNumber(['id', 'log']);
            Route::post('webhooks/{id}/logs/{log}/resend', [WebhooksController::class, 'resend'])->whereNumber(['id', 'log']);
        });
    }

    public static function docs(string $prefix = 'v1'): void
    {
        Route::get(trim($prefix, '/') . '/openapi.json', OpenApiController::class)->name('innertia.public-api.openapi');
    }

    /** @param string|null $specUrl absolute URL of openapi.json (default: url('api/v1/openapi.json')) */
    public static function developers(string $path = 'developers', ?string $specUrl = null): void
    {
        $route = Route::get($path, DevelopersController::class)->name('innertia.public-api.developers');

        if ($specUrl !== null) {
            $route->defaults('spec_url', $specUrl);
        }
    }
}
