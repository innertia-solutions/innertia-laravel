<?php

// stubs/api/api.private.php — rutas protegidas por API key (API mode)
// Publicado por innertia-kit via: php artisan vendor:publish --tag=innertia-routes
//
// Proteger rutas con: Route::middleware('apikey')->group(...)
//                 o:  Route::middleware('apikey:permission.name')->get(...)

use Illuminate\Support\Facades\Route;

// ── Rutas del producto ─────────────────────────────────────────────────────────
// Route::middleware('apikey')->group(function () {
//     // tus rutas aquí
// });
