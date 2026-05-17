<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Client / External Integrations
|--------------------------------------------------------------------------
|
| Estas rutas son para integraciones externas (ERPs, webhooks, clientes API).
| La autenticación es por API Key en el header X-Api-Key.
| El tenant se resuelve automáticamente desde la key — no se necesita X-Tenant.
|
| Tipos de keys:
|   inn_t_xxx — Tenant key: identifica el tenant, sin usuario.
|   inn_u_xxx — User key:   identifica tenant + usuario.
|
| Middleware disponible:
|   apikey            — solo autentica
|   apikey:perm.name  — autentica + verifica permiso
|
| Ejemplo:
|   Route::middleware('apikey:invoices.read')->get('/invoices', ...)
|
*/

Route::prefix('v1')->middleware(['apikey'])->group(function () {
    // Agrega aquí tus rutas de API pública para clientes
    // Route::get('/invoices',        [InvoicesClientController::class, 'index']);
    // Route::get('/invoices/{id}',   [InvoicesClientController::class, 'show']);
});
