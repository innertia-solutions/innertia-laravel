<?php

namespace Innertia\Backups\UseCases;

use Illuminate\Support\Facades\Storage;
use Innertia\Backups\Models\Backup;
use Innertia\Platform\Contracts\UseCase;

class DeleteBackup extends UseCase
{
    public function __construct(public readonly Backup $backup) {}

    public function execute(): void
    {
        if ($this->backup->path && Storage::disk($this->backup->disk)->exists($this->backup->path)) {
            Storage::disk($this->backup->disk)->delete($this->backup->path);
        }

        $this->backup->delete();
    }
}
