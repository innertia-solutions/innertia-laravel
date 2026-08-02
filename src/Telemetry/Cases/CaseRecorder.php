<?php

namespace Innertia\Telemetry\Cases;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Innertia\Telemetry\Events\CaseTouched;
use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Telemetry\Models\TelemetryCaseOccurrence;
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

        if (! $case) {
            try {
                $case = TelemetryCase::create([
                    'fingerprint'        => $fingerprint,
                    'title'              => class_basename($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
                    'exception_class'    => get_class($e),
                    'file'               => $e->getFile(),
                    'line'               => $e->getLine(),
                    'message_normalized' => $this->fingerprinter->normalizeMessage($e->getMessage()),
                    'status'             => 'open',
                    'times_seen'         => 1,
                    'first_seen'         => now(),
                    'last_seen'          => now(),
                    'last_trace_id'      => $traceId,
                    'sample_trace_ids'   => [$traceId],
                ]);

                $this->notify($case, isNew: true, isReopened: false);
                $this->signal($case, isNew: true, isReopened: false);

                return ['case' => $case, 'is_new' => true, 'is_reopened' => false];
            } catch (UniqueConstraintViolationException) {
                // Race: otro request creó el mismo fingerprint entre el SELECT y el INSERT.
                // Recargar y tratar esta ocurrencia como recurrencia.
                $case = TelemetryCase::where('fingerprint', $fingerprint)->firstOrFail();
            }
        }

        return $this->recordRecurrence($case, $traceId);
    }

    /**
     * @return array{case:TelemetryCase,is_new:bool,is_reopened:bool}
     */
    private function recordRecurrence(TelemetryCase $case, string $traceId): array
    {
        $isReopened = $case->status === 'resolved';

        // El cast 'array' devuelve null cuando la columna es null en DB; el ?? [] es load-bearing.
        $samples = $case->sample_trace_ids ?? [];
        array_unshift($samples, $traceId);
        $samples = array_slice(array_values(array_unique($samples)), 0, 5);

        $case->update([
            'times_seen'       => $case->times_seen + 1,
            'last_seen'        => now(),
            'last_trace_id'    => $traceId,
            'sample_trace_ids' => $samples,
            'status'           => $isReopened ? 'open' : $case->status,
            // Al reabrir, se abre un nuevo "episodio": se limpia notified_at para
            // que la reclamación atómica de notify() pueda volver a disparar el correo.
            'notified_at'      => $isReopened ? null : $case->notified_at,
        ]);

        $fresh = $case->fresh();
        $this->notify($fresh, isNew: false, isReopened: $isReopened);
        $this->signal($fresh, isNew: false, isReopened: $isReopened);

        return ['case' => $fresh, 'is_new' => false, 'is_reopened' => $isReopened];
    }

    /** Emite la señal realtime y suma la ocurrencia del día. Nunca lanza. */
    private function signal(TelemetryCase $case, bool $isNew, bool $isReopened): void
    {
        try {
            TelemetryCaseOccurrence::query()->upsert(
                [['case_id' => (string) $case->getKey(), 'day' => now()->toDateString(), 'count' => 1]],
                ['case_id', 'day'],
                ['count' => DB::raw('"count" + 1')]
            );
            event(new CaseTouched($case, $isNew, $isReopened));
        } catch (\Throwable) {
            // best-effort: la telemetría no debe romper el request
        }
    }

    private function notify(TelemetryCase $case, bool $isNew, bool $isReopened): void
    {
        if (! $isNew && ! $isReopened) return;

        $to = config('innertia.telemetry.support_email');
        if (! $to) return;

        // Reclamación atómica: solo UN caller pasa notified_at de null → now(). Bajo
        // concurrencia (dos requests reabriendo/creando el mismo caso), el segundo
        // update afecta 0 filas y no reenvía. notified_at ES el guard de idempotencia.
        $claimed = TelemetryCase::whereKey($case->getKey())
            ->whereNull('notified_at')
            ->update(['notified_at' => now()]);

        if ($claimed === 0) return;

        \Illuminate\Support\Facades\Mail::to($to)
            ->send(new \Innertia\Telemetry\Mail\SupportCaseMail($case, $isReopened));
    }
}
