<?php

namespace Innertia\Exports;

use Illuminate\Database\Eloquent\Model;
use Innertia\Models\Process;
use Innertia\Platform\Events\ProcessCompleted;
use Innertia\Platform\Events\ProcessFailed;

/**
 * Base class for tenant data exports (compliance / GDPR portability).
 *
 * Usage — define what to export in your app:
 *
 *   class ExportTenantData extends TenantExport
 *   {
 *       public function entities(): array
 *       {
 *           return [
 *               Client::class => [
 *                   'columns' => ['id', 'name', 'email', 'phone', 'created_at'],
 *                   'with' => [
 *                       Contact::class => ['columns' => ['id', 'name', 'role', 'email']],
 *                   ],
 *               ],
 *               Order::class => ['columns' => ['id', 'number', 'total', 'status', 'created_at']],
 *           ];
 *       }
 *   }
 *
 *   // Run synchronously:
 *   $process = (new ExportTenantData)->run($tenant);
 *
 *   // Run in background:
 *   $process = (new ExportTenantData)->queue($tenant);
 *   // → returns Process immediately (status: pending)
 *   // → poll GET /processes/{id} or subscribe for notification
 */
abstract class TenantExport
{
    abstract public function entities(): array;

    /**
     * Run the export synchronously.
     * Returns the completed Process (status: completed | failed).
     */
    public function run(Model $tenant): Process
    {
        $process = Process::start(
            type:     'export',
            category: static::class,
            metadata: ['tenant_id' => (string) $tenant->getKey()],
        );

        try {
            app(ExportPipeline::class)->run(
                entities: $this->entities(),
                tenantId: (string) $tenant->getKey(),
                process:  $process,
            );

            ProcessCompleted::dispatch($process->fresh());
        } catch (\Throwable $e) {
            ProcessFailed::dispatch($process->fresh());
            throw $e;
        }

        return $process->fresh();
    }

    /**
     * Dispatch the export as a background job.
     * Returns the Process immediately (status: pending).
     * Poll status via Olimpo or subscribe for 'web'/'mail' notification.
     */
    public function queue(Model $tenant): Process
    {
        $process = Process::start(
            type:     'export',
            category: static::class,
            metadata: ['tenant_id' => (string) $tenant->getKey()],
        );

        dispatch(function () use ($tenant, $process) {
            try {
                app(ExportPipeline::class)->run(
                    entities: $this->entities(),
                    tenantId: (string) $tenant->getKey(),
                    process:  $process,
                );

                ProcessCompleted::dispatch($process->fresh());
            } catch (\Throwable $e) {
                ProcessFailed::dispatch($process->fresh());
            }
        });

        return $process;
    }
}
