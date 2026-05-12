<?php

namespace Innertia\Exports;

use Illuminate\Database\Eloquent\Model;
use Innertia\Models\TenantExportRecord;

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
 *                       Contact::class => [
 *                           'columns' => ['id', 'name', 'role', 'email'],
 *                           // 'fk' => 'client_id', ← optional, inferred by convention
 *                       ],
 *                   ],
 *               ],
 *
 *               Order::class => [
 *                   'columns' => ['id', 'number', 'total', 'status', 'created_at'],
 *                   'with' => [
 *                       OrderItem::class => [
 *                           'columns' => ['id', 'product_name', 'qty', 'unit_price'],
 *                       ],
 *                   ],
 *               ],
 *
 *               Invoice::class => [
 *                   'columns' => ['id', 'number', 'amount', 'due_date', 'paid_at'],
 *               ],
 *           ];
 *       }
 *   }
 *
 * Run synchronously:
 *   $result = (new ExportTenantData)->run($tenant);
 *
 * Run in the background (queued):
 *   (new ExportTenantData)->queue($tenant);
 *
 * Nested 'with' FK convention:
 *   Parent model class basename in snake_case + '_id'
 *   e.g. Order → OrderItem FK = 'order_id'
 *   Override with 'fk' => 'custom_fk' if your schema differs.
 */
abstract class TenantExport
{
    /**
     * Declare the entities (and their columns) to include in the export.
     *
     * @return array<class-string, array{columns: string[], with?: array}>
     */
    abstract public function entities(): array;

    /**
     * Run the export synchronously and return the result.
     */
    public function run(Model $tenant): ExportResult
    {
        $record = TenantExportRecord::create([
            'tenant_id' => $tenant->getKey(),
            'status'    => 'pending',
        ]);

        return app(ExportPipeline::class)->run(
            entities: $this->entities(),
            tenantId: (string) $tenant->getKey(),
            record:   $record,
        );
    }

    /**
     * Dispatch the export as a background job.
     * The TenantExportRecord is created immediately; the pipeline runs async.
     */
    public function queue(Model $tenant): TenantExportRecord
    {
        $record = TenantExportRecord::create([
            'tenant_id' => $tenant->getKey(),
            'status'    => 'pending',
        ]);

        dispatch(fn () => app(ExportPipeline::class)->run(
            entities: $this->entities(),
            tenantId: (string) $tenant->getKey(),
            record:   $record,
        ));

        return $record;
    }
}
