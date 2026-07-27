<?php

namespace Innertia\Telemetry\Cases;

use Innertia\Telemetry\Models\TelemetryCase;
use Throwable;

class CaseRecorder
{
    public function __construct(private readonly CaseFingerprinter $fingerprinter) {}

    /**
     * @return array{case:TelemetryCase,is_new:bool,is_reopened:bool}
     */
    public function record(Throwable $e, string $traceId): array
    {
        $fingerprint = $this->fingerprinter->for(
            get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()
        );

        $case = TelemetryCase::where('fingerprint', $fingerprint)->first();
        $now  = now();

        if (! $case) {
            $case = TelemetryCase::create([
                'fingerprint'        => $fingerprint,
                'title'              => class_basename($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
                'exception_class'    => get_class($e),
                'file'               => $e->getFile(),
                'line'               => $e->getLine(),
                'message_normalized' => $this->fingerprinter->normalizeMessage($e->getMessage()),
                'status'             => 'open',
                'times_seen'         => 1,
                'first_seen'         => $now,
                'last_seen'          => $now,
                'last_trace_id'      => $traceId,
                'sample_trace_ids'   => [$traceId],
            ]);

            return ['case' => $case, 'is_new' => true, 'is_reopened' => false];
        }

        $isReopened = $case->status === 'resolved';

        $samples = $case->sample_trace_ids ?? [];
        array_unshift($samples, $traceId);
        $samples = array_slice(array_values(array_unique($samples)), 0, 5);

        $case->update([
            'times_seen'       => $case->times_seen + 1,
            'last_seen'        => $now,
            'last_trace_id'    => $traceId,
            'sample_trace_ids' => $samples,
            'status'           => $isReopened ? 'open' : $case->status,
        ]);

        return ['case' => $case->fresh(), 'is_new' => false, 'is_reopened' => $isReopened];
    }
}
