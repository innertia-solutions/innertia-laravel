<?php

namespace Innertia\Imports\Events;

use Illuminate\Database\Eloquent\Model;
use Innertia\Models\ImportRecord;
use Innertia\Platform\Events\DomainEvent;

/**
 * Fired when an import finishes successfully or partially (with row-level errors).
 *
 * Status will be 'completed' (zero errors) or 'partial' (some rows failed).
 * Subscribers on the ImportRecord are notified automatically via DomainEventRouter.
 *
 * Listener example:
 *
 *   ImportCompleted::class => [NotifyImportSubscribers::class],
 */
class ImportCompleted extends DomainEvent
{
    public function __construct(public readonly ImportRecord $importRecord) {}

    public function channels(): array
    {
        return ['mail', 'database'];
    }

    public function subscribable(): ?Model
    {
        return $this->importRecord;
    }

    public function payload(): array
    {
        return [
            'import_record_id' => $this->importRecord->id,
            'type'             => $this->importRecord->type,
            'status'           => $this->importRecord->status,
            'total_rows'       => $this->importRecord->total_rows,
            'processed_rows'   => $this->importRecord->processed_rows,
            'failed_rows'      => $this->importRecord->failed_rows,
            'completed_at'     => $this->importRecord->completed_at?->toISOString(),
        ];
    }
}
