<?php

namespace Innertia\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Innertia\Traits\HasFiles;
use Innertia\Traits\Subscribable;

/**
 * Unified async process record.
 * Tracks imports, exports, backups, and any long-running operation.
 *
 * Usage:
 *   $process = Process::start('import', ImportUsers::class, triggeredBy: auth()->id());
 *   $process->markProcessing();
 *   $process->updateProgress(50);
 *   $process->addMetadata(['processed_rows' => 500]);
 *   $process->complete();  // fires ProcessCompleted
 *   $process->fail('Out of memory'); // fires ProcessFailed
 */
class Process extends Model
{
    use HasUuids;
    use Subscribable;
    use HasFiles;

    protected $fillable = [
        'type',
        'category',
        'status',
        'progress',
        'metadata',
        'triggered_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ── Factory ───────────────────────────────────────────────────────────────

    public static function start(
        string $type,
        string $category,
        ?string $triggeredBy = null,
        array $metadata = [],
    ): static {
        return static::create([
            'type'         => $type,
            'category'     => $category,
            'status'       => 'pending',
            'progress'     => 0,
            'metadata'     => $metadata ?: null,
            'triggered_by' => $triggeredBy ?? (string) auth()->id(),
        ]);
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function markProcessing(): void
    {
        $this->update(['status' => 'processing', 'started_at' => now()]);
    }

    public function updateProgress(int $percent): void
    {
        $this->update(['progress' => max(0, min(100, $percent))]);
    }

    public function addMetadata(array $data): void
    {
        $this->update(['metadata' => array_merge($this->metadata ?? [], $data)]);
    }

    public function complete(array $metadata = []): void
    {
        $merged = array_merge($this->metadata ?? [], $metadata);
        $failed = ($merged['failed_rows'] ?? 0) > 0 || ($merged['errors'] ?? null) !== null;

        $this->update([
            'status'       => $failed ? 'partial' : 'completed',
            'progress'     => 100,
            'metadata'     => $merged ?: null,
            'completed_at' => now(),
        ]);
    }

    public function fail(string $message): void
    {
        $this->update([
            'status'       => 'failed',
            'metadata'     => array_merge($this->metadata ?? [], ['error' => $message]),
            'completed_at' => now(),
        ]);
    }

    // ── Access control ────────────────────────────────────────────────────────

    /**
     * Used by File::isAccessibleBy() when cascading permissions via owner.
     * Creator and subscribers can access files owned by this process.
     */
    public function canAccess(Authenticatable $user): bool
    {
        $userId = (string) $user->getAuthIdentifier();

        if ($this->triggered_by === $userId) {
            return true;
        }

        return $this->subscriptions()
            ->where('subscriber_id', $userId)
            ->exists();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'partial'], true);
    }

    public function progressPercent(): int
    {
        return (int) $this->progress;
    }
}
