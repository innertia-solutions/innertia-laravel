<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Innertia\Traits\Subscribable;

class ImportRecord extends Model
{
    use HasUuids;
    use Subscribable;

    protected $fillable = [
        'type',
        'status',
        'disk',
        'file_path',
        'original_filename',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'errors',
        'created_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'errors'       => 'array',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ── Status helpers ────────────────────────────────────────────────────────

    public function markProcessing(int $totalRows): void
    {
        $this->update([
            'status'     => 'processing',
            'total_rows' => $totalRows,
            'started_at' => now(),
        ]);
    }

    public function incrementProcessed(int $count = 1): void
    {
        $this->increment('processed_rows', $count);
    }

    public function addErrors(array $newErrors): void
    {
        $current = $this->errors ?? [];
        $this->update([
            'errors'      => array_merge($current, $newErrors),
            'failed_rows' => $this->failed_rows + count($newErrors),
        ]);
    }

    public function markCompleted(): void
    {
        $status = $this->failed_rows > 0 ? 'partial' : 'completed';

        $this->update([
            'status'       => $status,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status'       => 'failed',
            'errors'       => array_merge($this->errors ?? [], [['row' => null, 'message' => $message]]),
            'completed_at' => now(),
        ]);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function progressPercent(): int
    {
        if ($this->total_rows === 0) {
            return 0;
        }

        return (int) round(($this->processed_rows / $this->total_rows) * 100);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'partial'], true);
    }
}
