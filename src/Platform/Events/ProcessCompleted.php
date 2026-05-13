<?php

namespace Innertia\Platform\Events;

use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Models\Process;

/**
 * Fired when any async process finishes (completed or partial).
 * Subscribers on the Process model are notified via DomainEventRouter.
 *
 * Listener example (App\Providers\EventServiceProvider):
 *   ProcessCompleted::class => [NotifyProcessSubscribers::class],
 *
 * In listener, access type via $event->process->type ('import', 'export', 'backup', 'process').
 */
class ProcessCompleted extends DomainEvent
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
            'import'  => 'Importación completada',
            'export'  => 'Exportación lista',
            'backup'  => 'Respaldo completado',
            default   => 'Proceso completado',
        };

        $status = $this->process->status === 'partial'
            ? ' (con errores parciales)'
            : '';

        return [
            'title' => $label . $status,
            'body'  => class_basename($this->process->category) . ' finalizó correctamente.',
        ];
    }

    public function payload(): array
    {
        return [
            'process_id' => $this->process->id,
            'type'       => $this->process->type,
            'category'   => $this->process->category,
            'status'     => $this->process->status,
            'progress'   => $this->process->progress,
            'metadata'   => $this->process->metadata,
            'completed_at' => $this->process->completed_at?->toISOString(),
        ];
    }
}
