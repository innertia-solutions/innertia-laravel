<?php

namespace Innertia\Facades;

use Illuminate\Support\Facades\Facade;
use Innertia\Telemetry\TelemetryManager;

/**
 * @method static void log(string $channel, string $level, string $message, array $context = [])
 * @method static void tag(string $key, string|int|float|bool|null $value)
 * @see \Innertia\Telemetry\TelemetryManager
 */
class Telemetry extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TelemetryManager::class;
    }
}
