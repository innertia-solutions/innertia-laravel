<?php

namespace Innertia\Saas\Clients;

use Illuminate\Support\Facades\Route;
use Innertia\Saas\Clients\Http\ClientsController;

/**
 * Platform endpoints for clients and spaces. Mount inside the product's platform group:
 *   Routes::read();   // staff
 *   Routes::write();  // owner
 */
class Routes
{
    public static function read(string $controller = ClientsController::class): void
    {
        Route::post('clients/tree', [$controller, 'tree']);
        Route::get('clients/{client}', [$controller, 'show'])->whereUuid('client');
    }

    public static function write(string $controller = ClientsController::class): void
    {
        Route::post('clients', [$controller, 'store']);
        Route::patch('clients/{client}', [$controller, 'update'])->whereUuid('client');
        Route::patch('spaces/{key}/environment', [$controller, 'setEnvironment']);
    }
}
