<?php

namespace Innertia\Telemetry\Store;

use Innertia\Telemetry\Models\TelemetryEvent as EventModel;
use Innertia\Telemetry\Models\TelemetryTrace;
use Innertia\Telemetry\Support\PayloadSanitizer;
use Innertia\Telemetry\TelemetryCollector;

class TelemetryStore
{
    public function __construct(private readonly PayloadSanitizer $sanitizer) {}

    /**
     * Persiste el trace + todos los eventos acumulados del collector.
     *
     * @param array{method?:string,route?:string,url?:string,status?:int,duration_ms?:int,ip?:string,user_agent?:string,view?:string,payload?:array,response_summary?:array} $meta
     */
    public function persist(TelemetryCollector $collector, array $meta): void
    {
        $traceId = $collector->traceId();
        $batch   = $collector->flush();

        TelemetryTrace::create([
            'trace_id'         => $traceId,
            'session_id'       => $collector->sessionId(),
            'user_id'          => auth()->id(),
            'tenant'           => $collector->tenant(),
            'method'           => $meta['method'] ?? null,
            'route'            => $meta['route'] ?? null,
            'url'              => $meta['url'] ?? null,
            'view'             => $meta['view'] ?? null,
            'status'           => $meta['status'] ?? null,
            'duration_ms'      => $meta['duration_ms'] ?? null,
            'ip'               => $meta['ip'] ?? null,
            'user_agent'       => $meta['user_agent'] ?? null,
            'payload'          => $this->sanitizer->redact($meta['payload'] ?? []),
            'response_summary' => $this->sanitizer->redact($meta['response_summary'] ?? []),
            'occurred_at'      => now(),
        ]);

        foreach ($batch as $event) {
            EventModel::create([
                'app'         => $collector->appName(),
                'session_id'  => $collector->sessionId(),
                'trace_id'    => $traceId,
                'type'        => $event->type,
                'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s'),
                'duration_ms' => $event->durationMs !== null ? (int) round($event->durationMs) : null,
                'payload'     => $this->sanitizer->redact($event->payload),
                'context'     => $event->context,
            ]);
        }
    }
}
