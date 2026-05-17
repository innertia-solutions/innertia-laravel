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

// tenant.subdomain resuelve el tenant desde acme.api.tuproducto.com → "acme"
// apikey          valida X-Api-Key y cross-valida que pertenezca al tenant del subdominio
//
// Config en .env:  INNERTIA_API_DOMAIN=api.tuproducto.com
// Config en innertia.php → saas.api_domain
Route::prefix('v1')->middleware(['tenant.subdomain', 'apikey'])->group(function () {
    // Agrega aquí tus rutas de API pública para clientes
    // Route::middleware('apikey:invoices.read')->get('/invoices',      [InvoicesClientController::class, 'index']);
    // Route::middleware('apikey:invoices.read')->get('/invoices/{id}', [InvoicesClientController::class, 'show']);
});
