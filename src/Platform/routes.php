<?php

use Illuminate\Support\Facades\Route;
use Innertia\Auth\Middleware\Authenticate;
use Innertia\Platform\Http\Controllers\HistoryController;

Route::middleware(Authenticate::class)->prefix('history')->group(function () {
    Route::get('{entityType}/{id}', [HistoryController::class, 'index']);
});
