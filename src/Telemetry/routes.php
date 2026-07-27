<?php

use Illuminate\Support\Facades\Route;
use Innertia\Telemetry\Http\Controllers\ClientTelemetryController;
use Innertia\Telemetry\Http\Controllers\PresenceController;

Route::post('/platform/telemetry/client', [ClientTelemetryController::class, 'store'])
    ->middleware('api');

Route::post('/platform/telemetry/presence', [PresenceController::class, 'heartbeat'])
    ->middleware('api');
