<?php

namespace Innertia\Telemetry\Cases;

use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Telemetry\Models\TelemetryEvent;
use Innertia\Telemetry\Models\TelemetryTrace;

class ReviewPromptBuilder
{
    public function forCase(TelemetryCase $case): string
    {
        $trace  = TelemetryTrace::where('trace_id', $case->last_trace_id)->first();
        $events = $trace
            ? TelemetryEvent::where('trace_id', $trace->trace_id)->orderBy('occurred_at')->get()
            : collect();

        $lines = [];
        $lines[] = 'Eres un ingeniero senior. Revisa este caso de error de producción y propón causa raíz + fix.';
        $lines[] = '';
        $lines[] = '## Caso';
        $lines[] = "- Título: {$case->title}";
        $lines[] = "- Clase: {$case->exception_class}";
        $lines[] = "- Ubicación: {$case->file}:{$case->line}";
        $lines[] = "- Mensaje (normalizado): {$case->message_normalized}";
        $lines[] = "- times_seen: {$case->times_seen} (primero: {$case->first_seen}, último: {$case->last_seen})";
        $lines[] = '';

        if ($trace) {
            $lines[] = '## Request (trace)';
            $lines[] = "- {$trace->method} {$trace->route} → {$trace->status}";
            $lines[] = "- URL: {$trace->url}";
            $lines[] = "- Usuario: {$trace->user_id} / Tenant: {$trace->tenant} / Vista: {$trace->view}";
            $lines[] = '- Payload: ' . json_encode($trace->payload, JSON_UNESCAPED_UNICODE);
            $lines[] = '- Response: ' . json_encode($trace->response_summary, JSON_UNESCAPED_UNICODE);
            $lines[] = '';
        }

        if ($events->isNotEmpty()) {
            $lines[] = '## Eventos relacionados (orden cronológico)';
            foreach ($events as $e) {
                $lines[] = "- [{$e->type}] " . json_encode($e->payload, JSON_UNESCAPED_UNICODE);
            }
        }

        return implode("\n", $lines);
    }
}
