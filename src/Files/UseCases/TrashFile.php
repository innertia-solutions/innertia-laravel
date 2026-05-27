<?php

namespace Innertia\Files\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Innertia\Files\Events\FileTrashed;
use Innertia\Files\Models\File;

class TrashFile
{
    public function __construct(
        public readonly File $file,
        public readonly ?string $groupId = null,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): File
    {
        $groupId = $this->groupId ?? (string) Str::uuid();

        DB::transaction(function () use ($groupId) {
            $this->file->deleted_at     = now();
            $this->file->trash_group_id = $groupId;
            $this->file->save();
        });

        event(new FileTrashed($this->file, $groupId, $this->performedBy));

        return $this->file;
    }
}
