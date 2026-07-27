<?php

namespace Innertia\Telemetry\Console;

use Illuminate\Console\Command;
use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Telemetry\Models\TelemetryEvent;
use Innertia\Telemetry\Models\TelemetryPresence;
use Innertia\Telemetry\Models\TelemetryTrace;

class PruneTelemetryCommand extends Command
{
    protected $signature   = 'innertia:telemetry:prune {--days= : Retención de traces/events (default config)}';
    protected $description = 'Purga telemetría: traces/events por retention_days, cases por case_retention_days, presence por TTL';

    public function handle(): int
    {
        $days     = (int) ($this->option('days') ?? config('innertia.telemetry.retention_days', 7));
        $caseDays = (int) config('innertia.telemetry.case_retention_days', 90);
        $ttl      = (int) config('innertia.telemetry.presence.ttl', 60);

        if ($days <= 0) {
            $this->error('El número de días debe ser mayor a 0.');
            return self::FAILURE;
        }

        $cutoff     = now()->subDays($days);
        $caseCutoff = now()->subDays($caseDays);

        $events   = TelemetryEvent::where('occurred_at', '<', $cutoff)->delete();
        $traces   = TelemetryTrace::where('occurred_at', '<', $cutoff)->delete();
        $cases    = TelemetryCase::where('last_seen', '<', $caseCutoff)->delete();
        $presence = TelemetryPresence::where('last_seen_at', '<', now()->subSeconds($ttl))->delete();

        $this->info("Purgados: {$events} events, {$traces} traces, {$cases} casos, {$presence} presencias.");

        return self::SUCCESS;
    }
}
