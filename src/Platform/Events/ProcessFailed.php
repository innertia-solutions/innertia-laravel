<?php

namespace Innertia\Platform\Events;

use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Models\Process;

/**
 * Fired when an async process crashes at the job level.
 * Row/item-level errors produce 'partial' status and fire ProcessCompleted instead.
 */
class ProcessFailed extends DomainEvent
{
    public function __construct(public readonly Process $process) {}

    public function channels(): array
    {
        return ['mail', 'web'];
    }

    public function subscribable(): ?Model
    {
        return $this->process;
    }

    public function toWeb(): array
    {
        $label = match ($this->process->type) {
            'import' => 'Importación fallida',
            'export' => 'Exportación fallida',
            'backup' => 'Respaldo fallido',
            default  => 'Proceso fallido',
        };

        $error = $this->process->metadata['error'] ?? 'Error desconocido';

        return [
            'title' => $label,
            'body'  => $error,
        ];
    }

    public function payload(): array
    {
        return [
            'process_id'   => $this->process->id,
            'type'         => $this->process->type,
            'category'     => $this->process->category,
            'status'       => $this->process->status,
            'metadata'     => $this->process->metadata,
            'completed_at' => $this->process->completed_at?->toISOString(),
        ];
    }
}
