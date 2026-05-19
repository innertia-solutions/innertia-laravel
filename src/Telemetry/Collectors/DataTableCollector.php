<?php

namespace Innertia\Telemetry\Collectors;

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;

class DataTableCollector
{
    public static function handle(
        TelemetryCollector $collector,
        string $tableName,
        int    $rowCount,
        float  $durationMs,
    ): void {
        $collector->record(new TelemetryEvent(
            type: 'datatable',
            payload: [
                'table'       => $tableName,
                'rows'        => $rowCount,
                'duration_ms' => $durationMs,
                'filters'     => request()?->only(['search', 'sortColumns', 'page', 'perPage']) ?? [],
            ],
            context: [
                'tenant'  => $collector->tenant(),
                'user_id' => null,
                'route'   => request()?->method() . ' ' . request()?->path(),
                'env'     => app()->environment(),
                'source'  => request()?->header('X-Innertia-Source', 'cli'),
            ],
            durationMs: $durationMs,
        ));
    }
}
