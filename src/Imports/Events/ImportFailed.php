<?php

namespace Innertia\Imports\Events;

use Illuminate\Database\Eloquent\Model;
use Innertia\Models\ImportRecord;
use Innertia\Platform\Events\DomainEvent;

/**
 * Fired when an import fails at the job level (not row-level errors).
 *
 * This means the import process itself crashed (e.g. malformed file, out of memory).
 * Row-level errors produce 'partial' status and fire ImportCompleted instead.
 * Subscribers on the ImportRecord are notified automatically via DomainEventRouter.
 *
 * Listener example:
 *
 *   ImportFailed::class => [NotifyImportSubscribers::class, AlertAdminOnImportFailure::class],
 */
class ImportFailed extends DomainEvent
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
            'errors'           => $this->importRecord->errors ?? [],
            'completed_at'     => $this->importRecord->completed_at?->toISOString(),
        ];
    }
}
