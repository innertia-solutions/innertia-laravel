<?php

namespace Innertia\Backups\UseCases;

use Illuminate\Support\Collection;
use Innertia\Backups\Models\Backup;
use Innertia\Backups\Support\BackupSettings;
use Innertia\Platform\Contracts\UseCase;

/**
 * Poda de respaldos según la retención configurada.
 *
 * - Completados (no safety): se borra el que supere `retention_days` de antigüedad
 *   o quede fuera de los `keep` más recientes. El completado más reciente NUNCA se
 *   borra (si el respaldo lleva días fallando, no queremos quedarnos sin ninguno).
 * - Safety y fallidos: se borran al superar `retention_days`.
 * - pending/running: nunca se tocan.
 *
 * execute() devuelve la cantidad de respaldos eliminados.
 */
class PruneBackups extends UseCase
{
    public function __construct(
        public readonly ?int $retentionDays = null,
        public readonly ?int $keep = null,
        public readonly bool $dryRun = false,
    ) {}

    public function execute(): int
    {
        $days = $this->retentionDays ?? BackupSettings::retentionDays();
        $keep = $this->keep ?? BackupSettings::keep();
        $cutoff = $days > 0 ? now()->subDays($days) : null;

        $doomed = $this->completedToPrune($cutoff, $keep)
            ->merge($this->othersToPrune($cutoff));

        if (! $this->dryRun) {
            $doomed->each(fn (Backup $b) => (new DeleteBackup($b))->execute());
        }

        return $doomed->count();
    }

    /** @return Collection<int, Backup> */
    private function completedToPrune($cutoff, int $keep): Collection
    {
        $completed = Backup::query()
            ->where('is_safety', false)
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        // El más reciente queda siempre a salvo.
        return $completed->slice(1)->filter(function (Backup $b, int $index) use ($cutoff, $keep) {
            $beyondCount = $keep > 0 && $index >= $keep;
            $tooOld = $cutoff !== null && $b->created_at->lt($cutoff);

            return $beyondCount || $tooOld;
        })->values();
    }

    /** @return Collection<int, Backup> */
    private function othersToPrune($cutoff): Collection
    {
        if ($cutoff === null) {
            return collect();
        }

        return Backup::query()
            ->where('created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('is_safety', true)->whereNotIn('status', ['pending', 'running'])
                    ->orWhere('status', 'failed');
            })
            ->get();
    }
}
