<?php

namespace Innertia\Exports;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Innertia\Files\Models\File;
use Innertia\Platform\Models\Process;

class ExportPipeline
{
    private \PDO   $sqlite;
    private string $tmpSqlitePath;
    private string $tmpZipPath;
    private int    $totalRows = 0;

    public function run(
        array   $entities,
        ?string $tenantId,
        Process $process,
    ): ExportResult {
        $process->markProcessing();

        try {
            $this->boot($tenantId);
            $this->copyEntities($entities, $tenantId);
            $this->verify();

            $result = $this->compress($tenantId, $process);

            $this->cleanup();

            return $result;

        } catch (\Throwable $e) {
            $this->cleanup();
            $process->fail($e->getMessage());
            throw $e;
        }
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    private function boot(?string $tenantId): void
    {
        $slug = $tenantId ?? 'app';
        $ts   = now()->format('YmdHis');
        $dir  = storage_path('app/temp/exports');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->tmpSqlitePath = "{$dir}/{$slug}-{$ts}.sqlite";
        $this->tmpZipPath    = "{$dir}/{$slug}-{$ts}.zip";

        $this->sqlite = new \PDO("sqlite:{$this->tmpSqlitePath}");
        $this->sqlite->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->sqlite->exec('PRAGMA journal_mode = WAL');
        $this->sqlite->exec('PRAGMA foreign_keys = ON');
    }

    // ── Copy ──────────────────────────────────────────────────────────────────

    private function copyEntities(array $entities, ?string $tenantId, ?array $parentIds = null, ?string $foreignKey = null): void
    {
        foreach ($entities as $entityClass => $config) {
            $columns = $config['columns'] ?? ['*'];
            $nested  = $config['with']    ?? [];
            $table   = (new $entityClass)->getTable();

            $this->createTable($table, $entityClass, $columns);

            $query = $entityClass::query();

            if ($parentIds !== null && $foreignKey !== null) {
                $query->whereIn($foreignKey, $parentIds);
            } elseif ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }

            if ($columns !== ['*']) {
                $query->select($columns);
            }

            $rows = $query->get();
            $ids  = [];

            foreach ($rows as $row) {
                $data = $row->toArray();

                if ($columns !== ['*']) {
                    $data = array_intersect_key($data, array_flip($columns));
                }

                $this->insertRow($table, $data);
                $ids[] = $row->getKey();
                $this->totalRows++;
            }

            if (! empty($nested) && ! empty($ids)) {
                foreach ($nested as $nestedClass => $nestedConfig) {
                    $fk = $nestedConfig['fk'] ?? Str::snake(class_basename($entityClass)) . '_id';
                    $this->copyEntities([$nestedClass => $nestedConfig], $tenantId, $ids, $fk);
                }
            }
        }
    }

    // ── SQLite helpers ────────────────────────────────────────────────────────

    private function createTable(string $table, string $entityClass, array $columns): void
    {
        if ($columns === ['*']) {
            $columns = \Schema::getColumnListing((new $entityClass)->getTable());
        }

        $defs = array_map(fn ($col) => "\"{$col}\" TEXT", $columns);
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS \"{$table}\" (" . implode(', ', $defs) . ")");
    }

    private function insertRow(string $table, array $data): void
    {
        if (empty($data)) {
            return;
        }

        $cols         = implode(', ', array_map(fn ($k) => "\"{$k}\"", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $stmt         = $this->sqlite->prepare("INSERT INTO \"{$table}\" ({$cols}) VALUES ({$placeholders})");
        $stmt->execute(array_map(
            fn ($v) => is_array($v) || is_object($v) ? json_encode($v) : $v,
            array_values($data)
        ));
    }

    // ── Verify ────────────────────────────────────────────────────────────────

    private function verify(): void
    {
        $result = $this->sqlite->query('PRAGMA integrity_check')->fetchColumn();

        if ($result !== 'ok') {
            throw new \RuntimeException("SQLite integrity check failed: {$result}");
        }

        unset($this->sqlite);
    }

    // ── Compress + upload as File record ─────────────────────────────────────

    private function compress(?string $tenantId, Process $process): ExportResult
    {
        $zip = new \ZipArchive();
        $zip->open($this->tmpZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($this->tmpSqlitePath, basename($this->tmpSqlitePath));
        $zip->close();

        $disk     = config('innertia.exports.disk', config('filesystems.cloud', 'local'));
        $slug     = $tenantId ?? 'app';
        $filename = basename($this->tmpZipPath);
        $path     = "exports/{$slug}/" . now()->format('Y/m') . "/{$filename}";
        $checksum = md5_file($this->tmpZipPath);

        Storage::disk($disk)->put($path, file_get_contents($this->tmpZipPath));
        $size = Storage::disk($disk)->size($path);

        // Create a File record — restricted to process owner access
        $file = File::create([
            'disk'          => $disk,
            'path'          => $path,
            'original_name' => $filename,
            'mime_type'     => 'application/zip',
            'extension'     => 'zip',
            'size'          => $size,
            'visibility'    => 'restricted',
            'created_by'    => $process->triggered_by,
        ]);

        $process->attachFile($file);
        $process->complete([
            'file_id'      => $file->id,
            'size_mb'      => $file->sizeMb(),
            'checksum'     => $checksum,
            'rows_exported'=> $this->totalRows,
            'tenant_id'    => $tenantId,
        ]);

        return new ExportResult(
            disk:         $disk,
            path:         $path,
            size:         $size,
            checksum:     $checksum,
            exportedAt:   now(),
            rowsExported: $this->totalRows,
        );
    }

    // ── Cleanup ───────────────────────────────────────────────────────────────

    private function cleanup(): void
    {
        @unlink($this->tmpSqlitePath ?? '');
        @unlink($this->tmpZipPath ?? '');
    }
}
