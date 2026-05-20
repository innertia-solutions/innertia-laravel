<?php

use Illuminate\Support\Facades\Route;
use Innertia\Devtools\Http\Controllers\DbmsController;
use Innertia\Devtools\Http\Controllers\TinkerController;

Route::prefix('innertia/devtools')
    ->middleware(['olimpo.auth', 'devtools.guard'])
    ->group(function () {
        // DB Browser
        Route::get('dbms/tables', [DbmsController::class, 'tables']);
        Route::post('dbms/tables/{table}/rows', [DbmsController::class, 'rows']);
        Route::put('dbms/tables/{table}/rows/{id}', [DbmsController::class, 'updateRow']);
        Route::post('dbms/query', [DbmsController::class, 'query']);

        // Remote Tinker
        Route::post('tinker/sessions', [TinkerController::class, 'create']);
        Route::post('tinker/sessions/{id}/eval', [TinkerController::class, 'eval']);
        Route::delete('tinker/sessions/{id}', [TinkerController::class, 'destroy']);
    });
