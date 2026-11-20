<?php

namespace Innertia\Billing;

use Illuminate\Support\Facades\Route;
use Innertia\Billing\Http\BackofficeController;
use Innertia\Billing\Http\CollectionsController;
use Innertia\Billing\Http\ReconciliationController;
use Innertia\Billing\Http\ReportsController;
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

            // Collections (stage 3)
            Route::get('adjustments', [CollectionsController::class, 'adjustments']);
            Route::get('clients/{client}/terms', [CollectionsController::class, 'terms'])->whereUuid('client');
            Route::get('clients/{client}/account', [CollectionsController::class, 'account'])->whereUuid('client');
            Route::get('clients/{client}/account/export', [CollectionsController::class, 'exportAccount'])->whereUuid('client');
            Route::get('activity', [CollectionsController::class, 'activity']);
            Route::get('reconciliation/imports', [ReconciliationController::class, 'imports']);
            Route::get('reconciliation/imports/{import}', [ReconciliationController::class, 'showImport'])->whereUuid('import');
            Route::get('reconciliation/transactions', [ReconciliationController::class, 'transactions']);
            Route::get('reconciliation/open-invoices', [ReconciliationController::class, 'openInvoices']);
            Route::get('reports/{report}', [ReportsController::class, 'show'])->whereIn('report', \Innertia\Billing\Support\BillingReports::REPORTS);
            Route::get('reports/{report}/export', [ReportsController::class, 'export'])->whereIn('report', \Innertia\Billing\Support\BillingReports::REPORTS);
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
            Route::post('run/{job}', [OverviewController::class, 'run'])->whereIn('job', ['close', 'dunning', 'reminders']);

            // Collections (stage 3)
            Route::post('invoices/{invoice}/void', [InvoicesController::class, 'void'])->whereUuid('invoice');
            Route::post('invoices/{invoice}/reissue', [InvoicesController::class, 'reissue'])->whereUuid('invoice');
            Route::post('invoices/{invoice}/waive-late-fees', [InvoicesController::class, 'waiveLateFees'])->whereUuid('invoice');
            Route::post('adjustments', [CollectionsController::class, 'storeAdjustment']);
            Route::post('adjustments/{adjustment}/cancel', [CollectionsController::class, 'cancelAdjustment'])->whereUuid('adjustment');
            Route::post('credit-notes', [CollectionsController::class, 'storeCreditNote']);
            Route::put('clients/{client}/terms', [CollectionsController::class, 'saveTerms'])->whereUuid('client');
            Route::post('clients/{client}/apply-credit', [CollectionsController::class, 'applyCredit'])->whereUuid('client');
            Route::post('reconciliation/preview', [ReconciliationController::class, 'preview']);
            Route::post('reconciliation/imports', [ReconciliationController::class, 'store']);
            Route::post('reconciliation/rematch', [ReconciliationController::class, 'rematch']);
            Route::post('reconciliation/transactions/{transaction}/match', [ReconciliationController::class, 'match'])->whereUuid('transaction');
            Route::post('reconciliation/transactions/{transaction}/unmatch', [ReconciliationController::class, 'unmatch'])->whereUuid('transaction');
            Route::post('reconciliation/transactions/{transaction}/ignore', [ReconciliationController::class, 'ignore'])->whereUuid('transaction');
            Route::post('reconciliation/transactions/{transaction}/restore', [ReconciliationController::class, 'restore'])->whereUuid('transaction');
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
            Route::get('account', [BackofficeController::class, 'account']);
        });
    }
}
