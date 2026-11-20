<?php

namespace Innertia\Billing;

use Illuminate\Support\Facades\Route;
use Innertia\Billing\Http\BackofficeController;
use Innertia\Billing\Http\InvoicesController;
use Innertia\Billing\Http\OverviewController;
use Innertia\Billing\Http\PaymentsController;
use Innertia\Billing\Http\PlansController;
use Innertia\Billing\Http\SpacesController;

/**
 * Billing endpoints. No auth of their own: mount them inside the product groups.
 *
 *   // platform group (staff reads, owner writes)
 *   \Innertia\Billing\Routes::platform();
 *   Route::middleware('platform.owner')->group(fn () => \Innertia\Billing\Routes::platformWrite());
 *
 *   // backoffice group (active space), read-only
 *   Route::middleware('permission:billing.view')->group(fn () => \Innertia\Billing\Routes::backoffice());
 */
class Routes
{
    public static function platform(string $prefix = 'billing'): void
    {
        Route::prefix($prefix)->group(function () {
            Route::get('plans', [PlansController::class, 'index']);
            Route::get('meters', [PlansController::class, 'meters']);
            Route::get('spaces/{key}', [SpacesController::class, 'show']);
            Route::get('invoices', [InvoicesController::class, 'index']);
            Route::get('invoices/{invoice}', [InvoicesController::class, 'show'])->whereUuid('invoice');
            Route::get('invoices/{invoice}/pdf', [InvoicesController::class, 'pdf'])->whereUuid('invoice');
            Route::get('payments', [PaymentsController::class, 'index']);
            Route::get('clients/totals', [OverviewController::class, 'clients']);
            Route::get('settings', [OverviewController::class, 'settings']);
        });
    }

    public static function platformWrite(string $prefix = 'billing'): void
    {
        Route::prefix($prefix)->group(function () {
            Route::post('plans', [PlansController::class, 'store']);
            Route::patch('plans/{plan}', [PlansController::class, 'update'])->whereUuid('plan');
            Route::post('spaces/{key}/subscription', [SpacesController::class, 'start']);
            Route::patch('spaces/{key}/subscription', [SpacesController::class, 'update']);
            Route::post('spaces/{key}/subscription/{action}', [SpacesController::class, 'action']);
            Route::post('invoices/{invoice}/issue', [InvoicesController::class, 'issue'])->whereUuid('invoice');
            Route::post('invoices/{invoice}/resend', [InvoicesController::class, 'resend'])->whereUuid('invoice');
            Route::post('payments', [PaymentsController::class, 'store']);
            Route::put('settings', [OverviewController::class, 'saveSettings']);
            Route::post('run/{job}', [OverviewController::class, 'run'])->whereIn('job', ['close', 'dunning']);
        });
    }

    public static function backoffice(string $prefix = 'billing'): void
    {
        Route::prefix($prefix)->group(function () {
            Route::get('overview', [BackofficeController::class, 'overview']);
            Route::get('invoices', [BackofficeController::class, 'invoices']);
            Route::get('invoices/{invoice}', [BackofficeController::class, 'invoice'])->whereUuid('invoice');
            Route::get('invoices/{invoice}/pdf', [BackofficeController::class, 'pdf'])->whereUuid('invoice');
            Route::get('payments', [BackofficeController::class, 'payments']);
        });
    }
}
