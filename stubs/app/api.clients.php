<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Client / External Integrations (single-app mode)
|--------------------------------------------------------------------------
|
| Autenticación por X-Api-Key header. Sin subdominio — la app es una sola.
|
| Tipos de keys:
|   inn_a_xxx — App key:  sin usuario (server-to-server)
|   inn_u_xxx — User key: autenticado como un usuario específico
|
| Middleware:
|   apikey            — solo autentica
|   apikey:perm.name  — autentica + verifica permiso
|
*/

Route::prefix('v1')->middleware(['apikey'])->group(function () {

    // ── API Key self-management (requiere permiso api_keys.manage) ────────────
    Route::get    ('api-keys',      [\Innertia\ApiKeys\Http\Controllers\ApiKeysClientController::class, 'index']);
    Route::post   ('api-keys',      [\Innertia\ApiKeys\Http\Controllers\ApiKeysClientController::class, 'store']);
    Route::delete ('api-keys/{id}', [\Innertia\ApiKeys\Http\Controllers\ApiKeysClientController::class, 'destroy']);

    // ── Agrega aquí tus rutas de API pública para clientes ───────────────────
    // Route::middleware('apikey:invoices.read')->get('/invoices',      [InvoicesClientController::class, 'index']);
    // Route::middleware('apikey:invoices.read')->get('/invoices/{id}', [InvoicesClientController::class, 'show']);
});
