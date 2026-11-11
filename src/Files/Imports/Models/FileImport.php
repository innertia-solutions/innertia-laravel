<?php

namespace Innertia\Files\Imports\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Innertia\Files\Imports\Enums\FileImportKind;
use Innertia\Files\Imports\Enums\FileImportStatus;

/**
 * Un proceso de importación (o refresh) desde un proveedor. Lo crea el controller
 * en `pending`; el UseCase en cola lo pasa a running → done | failed y va anotando
 * cada ítem (contadores + detalle acotado a ITEMS_CAP entradas).
 */
class FileImport extends Model
{
    use HasUuids;

    public const ITEMS_CAP = 5000;

    /** Atributos que el producto puede aplicar a cada File/Directory creado. */
    public const ALLOWED_ATTRIBUTES = ['owner_type', 'owner_id'];

    private const COUNTERS = [
        'imported'  => 'imported',
        'updated'   => 'updated',
        'unchanged' => 'unchanged',
        'skipped'   => 'skipped',
        'failed'    => 'failed_count',
    ];

    protected $table = 'file_imports';

    protected $fillable = [
        'kind', 'provider', 'source_items', 'target_directory_id', 'native_format', 'visibility',
        'file_attributes', 'file_id', 'status', 'found', 'imported', 'updated', 'unchanged', 'skipped',
        'failed_count', 'items', 'error', 'trace_id', 'created_by', 'started_at', 'finished_at',
    ];

    protected $attributes = [
        'status'        => 'pending',
        'native_format' => 'office',
        'visibility'    => 'auth',
        'found'         => 0,
        'imported'      => 0,
        'updated'       => 0,
        'unchanged'     => 0,
        'skipped'       => 0,
        'failed_count'  => 0,
    ];

    protected $casts = [
        'kind'            => FileImportKind::class,
        'status'          => FileImportStatus::class,
        'source_items'    => 'array',
        'file_attributes' => 'array',
        'items'           => 'array',
        'found'           => 'integer',
        'imported'        => 'integer',
        'updated'         => 'integer',
        'unchanged'       => 'integer',
        'skipped'         => 'integer',
        'failed_count'    => 'integer',
        'started_at'      => 'datetime',
        'finished_at'     => 'datetime',
    ];

    public static function filterAttributes(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(self::ALLOWED_ATTRIBUTES));
    }

    /** Anota un ítem: suma su contador y agrega el detalle (hasta ITEMS_CAP). No guarda. */
    public function record(string $result, array $entry): void
    {
        $column = self::COUNTERS[$result] ?? throw new \InvalidArgumentException("Resultado de import desconocido: {$result}");

        $this->{$column} = (int) $this->{$column} + 1;

        $items = $this->items ?? [];
        if (count($items) < self::ITEMS_CAP) {
            $items[] = ['result' => $result] + $entry;
            $this->items = $items;
        }
    }

    public function markRunning(string $traceId): void
    {
        $this->forceFill(['status' => FileImportStatus::Running, 'started_at' => now(), 'trace_id' => $traceId])->save();
    }

    public function markDone(): void
    {
        $this->forceFill(['status' => FileImportStatus::Done, 'finished_at' => now()])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill(['status' => FileImportStatus::Failed, 'error' => $error, 'finished_at' => now()])->save();
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [FileImportStatus::Done, FileImportStatus::Failed], true);
    }
}
