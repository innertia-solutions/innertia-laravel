<?php

namespace Innertia\Exports;

use Illuminate\Support\Carbon;

final class ExportResult
{
    public function __construct(
        public readonly string  $disk,
        public readonly string  $path,
        public readonly int     $size,
        public readonly string  $checksum,
        public readonly Carbon  $exportedAt,
        public readonly int     $rowsExported,
    ) {}

    public function sizeMb(): float
    {
        return round($this->size / 1024 / 1024, 2);
    }

    public function toArray(): array
    {
        return [
            'disk'          => $this->disk,
            'path'          => $this->path,
            'size'          => $this->size,
            'size_mb'       => $this->sizeMb(),
            'checksum'      => $this->checksum,
            'exported_at'   => $this->exportedAt->toISOString(),
            'rows_exported' => $this->rowsExported,
        ];
    }
}
