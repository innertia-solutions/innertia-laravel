<?php

namespace Innertia\Telemetry\Console;

use Illuminate\Console\Command;
use Innertia\Telemetry\Models\TelemetryEvent;

class PruneTelemetryCommand extends Command
{
    protected $signature   = 'telemetry:prune {--days= : Días de retención (default: config telemetry.retention_days)}';
    protected $description = 'Elimina eventos de telemetría más antiguos que N días';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('telemetry.retention_days', 7));

        if ($days <= 0) {
            $this->error('El número de días debe ser mayor a 0.');
            return self::FAILURE;
        }

        $cutoff  = now()->subDays($days);
        $deleted = TelemetryEvent::where('occurred_at', '<', $cutoff)->delete();

        $this->info("Eliminados {$deleted} eventos de telemetría anteriores a {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
