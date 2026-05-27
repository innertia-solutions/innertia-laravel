<?php

namespace Innertia\Files\Directories\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Innertia\Files\Directories\Events\DirectoryTrashed;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Models\File;

class TrashDirectory
{
    public function __construct(
        public readonly Directory $directory,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): Directory
    {
        $groupId = (string) Str::uuid();
        $now     = now();
        $prefix  = $this->directory->path;

        DB::transaction(function () use ($groupId, $now, $prefix) {
            $this->directory->deleted_at     = $now;
            $this->directory->trash_group_id = $groupId;
            $this->directory->save();

            // Cascade to NOT-already-trashed descendants only
            Directory::query()
                ->where('path', 'like', $prefix . '%')
                ->where('id', '!=', $this->directory->id)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at'     => $now,
                    'trash_group_id' => $groupId,
                    'updated_at'     => $now,
                ]);

            // Cascade to files in any of the trashed directories
            if (Schema::hasColumn('files', 'directory_id')) {
                $descendantIds = Directory::withTrashed()
                    ->where('path', 'like', $prefix . '%')
                    ->pluck('id');

                File::query()
                    ->whereIn('directory_id', $descendantIds)
                    ->whereNull('deleted_at')
                    ->update([
                        'deleted_at'     => $now,
                        'trash_group_id' => $groupId,
                        'updated_at'     => $now,
                    ]);
            }
        });

        event(new DirectoryTrashed($this->directory, $groupId, $this->performedBy));

        return $this->directory;
    }
}
