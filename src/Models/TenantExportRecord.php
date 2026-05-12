<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class TenantExportRecord extends Model
{
    protected $table = 'tenant_exports';

    protected $fillable = [
        'tenant_id',
        'status',
        'disk',
        'path',
        'size',
        'checksum',
        'error',
        'completed_at',
    ];

    protected $casts = [
        'size'         => 'integer',
        'completed_at' => 'datetime',
    ];

    public function markProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markCompleted(string $disk, string $path, int $size, string $checksum): void
    {
        $this->update([
            'status'       => 'completed',
            'disk'         => $disk,
            'path'         => $path,
            'size'         => $size,
            'checksum'     => $checksum,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error'  => $error,
        ]);
    }

    public function downloadUrl(int $ttlMinutes = 60): string
    {
        return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes($ttlMinutes));
    }

    public function sizeMb(): float
    {
        return $this->size ? round($this->size / 1024 / 1024, 2) : 0;
    }
}
