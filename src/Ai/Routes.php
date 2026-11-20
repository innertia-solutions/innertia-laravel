<?php

namespace Innertia\Ai;

use Illuminate\Support\Facades\Route;
use Innertia\Ai\Http\AiPricingController;
use Innertia\Ai\Http\AiUsageReportController;

/**
 * AI pricing and usage endpoints. No auth of their own: mount them inside the product groups.
 *
 *   // platform group (prefix 'platform')
 *   \Innertia\Ai\Routes::platform();                 // GET ai/models/prices, GET ai/usage/report
 *   Route::middleware('platform.owner')->group(fn () => \Innertia\Ai\Routes::platformWrite());  // PUT ai/models/{id}/prices
 *
 *   // backoffice group (tenant)
 *   \Innertia\Ai\Routes::tenant();                   // GET ai/usage/summary?month=YYYY-MM
 */
class Routes
{
    public static function platform(string $prefix = 'ai'): void
    {
        Route::prefix($prefix)->group(function () {
            Route::get('models/prices', [AiPricingController::class, 'index']);
            Route::get('usage/report', [AiUsageReportController::class, 'platform']);
        });
    }

    public static function platformWrite(string $prefix = 'ai'): void
    {
        Route::prefix($prefix)->group(function () {
            Route::put('models/{id}/prices', [AiPricingController::class, 'update'])->whereUuid('id');
        });
    }

    public static function tenant(string $prefix = 'ai'): void
    {
        Route::prefix($prefix)->group(function () {
            Route::get('usage/summary', [AiUsageReportController::class, 'tenant']);
        });
    }
}
