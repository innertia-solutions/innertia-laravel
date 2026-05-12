<?php

namespace Innertia\Exports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Innertia\Models\TenantExportRecord;

class ExportPipeline
{
    private \PDO    $sqlite;
    private string  $tmpSqlitePath;
    private string  $tmpZipPath;
    private int     $totalRows = 0;

    public function run(
        array                $entities,
        ?string              $tenantId,
        TenantExportRecord   $record,
    ): ExportResult {
        $record->markProcessing();

        try {
            $this->boot($tenantId);
            $this->copyEntities($entities, $tenantId);
            $this->verify();

            $zipPath  = $this->compress();
            $result   = $this->upload($tenantId, $zipPath, $record);

            $this->cleanup();

            return $result;

        } catch (\Throwable $e) {
            $this->cleanup();
            $record->markFailed($e->getMessage());
            throw $e;
        }
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    private function boot(?string $tenantId): void
    {
        $slug = $tenantId ?? 'app';
        $ts   = now()->format('YmdHis');

        $dir = storage_path('app/temp/exports');
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
            $columns  = $config['columns'] ?? ['*'];
            $nested   = $config['with']    ?? [];

            $table    = (new $entityClass)->getTable();

            $this->createTable($table, $entityClass, $columns);

            $query = $entityClass::query();

            if ($parentIds !== null && $foreignKey !== null) {
                // Nested entity — filter by parent IDs
                $query->whereIn($foreignKey, $parentIds);
            } elseif ($tenantId !== null) {
                // Root entity — filter by tenant
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

            // Process nested entities
            if (! empty($nested) && ! empty($ids)) {
                foreach ($nested as $nestedClass => $nestedConfig) {
                    // FK convention: snake_case(ParentModel) + '_id'
                    // Override with explicit 'fk' key if needed
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
            // Get actual columns from the DB schema
            $columns = \Schema::getColumnListing((new $entityClass)->getTable());
        }

        $defs = array_map(fn ($col) => "\"{$col}\" TEXT", $columns);
        $sql  = "CREATE TABLE IF NOT EXISTS \"{$table}\" (" . implode(', ', $defs) . ")";

        $this->sqlite->exec($sql);
    }

    private function insertRow(string $table, array $data): void
    {
        if (empty($data)) {
            return;
        }

        $cols         = implode(', ', array_map(fn ($k) => "\"{$k}\"", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $stmt         = $this->sqlite->prepare("INSERT INTO \"{$table}\" ({$cols}) VALUES ({$placeholders})");

        $stmt->execute(array_map(fn ($v) => is_array($v) || is_object($v) ? json_encode($v) : $v, array_values($data)));
    }

    // ── Verify ────────────────────────────────────────────────────────────────

    private function verify(): void
    {
        $result = $this->sqlite->query('PRAGMA integrity_check')->fetchColumn();

        if ($result !== 'ok') {
            throw new \RuntimeException("SQLite integrity check failed: {$result}");
        }

        // Close connection before compression
        unset($this->sqlite);
    }

    // ── Compress ──────────────────────────────────────────────────────────────

    private function compress(): string
    {
        $zip = new \ZipArchive();
        $zip->open($this->tmpZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($this->tmpSqlitePath, basename($this->tmpSqlitePath));
        $zip->close();

        return $this->tmpZipPath;
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    private function upload(?string $tenantId, string $zipPath, TenantExportRecord $record): ExportResult
    {
        $disk     = config('innertia.exports.disk', config('filesystems.cloud', 'local'));
        $slug     = $tenantId ?? 'app';
        $filename = basename($zipPath);
        $path     = "exports/{$slug}/" . now()->format('Y/m') . "/{$filename}";

        Storage::disk($disk)->put($path, file_get_contents($zipPath));

        $size     = Storage::disk($disk)->size($path);
        $checksum = md5_file($zipPath);

        $record->markCompleted($disk, $path, $size, $checksum);

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
        @unlink($this->tmpSqlitePath);
        @unlink($this->tmpZipPath);
    }
}
