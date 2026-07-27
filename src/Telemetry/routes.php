<?php

use Illuminate\Support\Facades\Route;
use Innertia\Telemetry\Http\Controllers\ClientTelemetryController;

Route::post('/platform/telemetry/client', [ClientTelemetryController::class, 'store'])
    ->middleware('api');
