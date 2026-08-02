<?php

namespace Innertia\Telemetry;

use Illuminate\Support\Facades\Schema;
use Innertia\Telemetry\Models\TelemetryEvent as EventModel;
use Innertia\Telemetry\Support\PayloadSanitizer;

class TelemetryManager
{
    public function __construct(private readonly PayloadSanitizer $sanitizer) {}

    /**
     * Registra un log de dominio en un canal. Escritura directa (robusta en
     * jobs/webhooks fuera del ciclo request → no depende del flush del middleware).
     */
    public function log(string $channel, string $level, string $message, array $context = []): void
    {
        $collector = app()->bound(TelemetryCollector::class)
            ? app(TelemetryCollector::class)
            : null;

        $row = [
            'app'         => config('innertia.telemetry.app_name', config('app.name', 'app')),
            'session_id'  => $collector?->sessionId() ?? 'domain',
            'trace_id'    => $collector?->traceId(),
            'type'        => 'log',
            'channel'     => $channel,
            'occurred_at' => now(),
            'duration_ms' => null,
            'payload'     => ['level' => $level, 'message' => $message, 'context' => $this->sanitizer->redact($context)],
            'context'     => ['source' => 'domain', 'env' => app()->environment()],
        ];

        if (Schema::hasColumn('telemetry_events', 'tenant_id')) {
            $row['tenant_id'] = $collector?->tenant();
        }

        EventModel::create($row);
    }
}
