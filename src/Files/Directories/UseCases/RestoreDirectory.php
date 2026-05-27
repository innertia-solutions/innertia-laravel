<?php

namespace Innertia\Files\Directories\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Innertia\Files\Directories\Events\DirectoryRestored;
use Innertia\Files\Directories\Exceptions\OrphanedRestoreException;
use Innertia\Files\Directories\Exceptions\RestoreCollisionException;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Models\File;

class RestoreDirectory
{
    public function __construct(
        public readonly Directory $directory,
        public readonly ?Directory $relocateParent = null,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): Directory
    {
        if ($this->directory->trash_group_id === null) {
            throw new \LogicException('Directory not in trash.');
        }

        $newParentId = $this->directory->parent_id;
        $relocated   = false;

        if ($newParentId !== null) {
            $parentExists = Directory::query()
                ->whereKey($newParentId)
                ->exists();  // alive parents only (SoftDeletes excludes soft-deleted)

            if (! $parentExists) {
                if ($this->relocateParent === null) {
                    throw OrphanedRestoreException::for($this->directory->id);
                }
                $newParentId = $this->relocateParent->id;
                $relocated   = true;
            }
        }

        $this->validateNoLiveSiblingCollision($newParentId);

        $groupId = $this->directory->trash_group_id;

        DB::transaction(function () use ($groupId, $newParentId, $relocated) {
            // Restore entire group first
            Directory::withTrashed()
                ->where('trash_group_id', $groupId)
                ->update([
                    'deleted_at'     => null,
                    'trash_group_id' => null,
                    'updated_at'     => now(),
                ]);

            // Restore files in the same trash group
            if (Schema::hasColumn('files', 'directory_id')) {
                File::withTrashed()
                    ->where('trash_group_id', $groupId)
                    ->update([
                        'deleted_at'     => null,
                        'trash_group_id' => null,
                        'updated_at'     => now(),
                    ]);
            }

            if ($relocated) {
                $dir       = Directory::find($this->directory->id);
                $newParent = $this->relocateParent;
                $oldPath   = $dir->path;
                $newPath   = ($newParent?->path ?? '/') . $dir->id . '/';
                $depthDelta = ($newParent?->depth ?? 0) + 1 - $dir->depth;

                $dir->parent_id = $newParentId;
                $dir->path      = $newPath;
                $dir->depth     = ($newParent?->depth ?? 0) + 1;
                $dir->save();

                // Cascade to restored descendants (already live at this point)
                Directory::query()
                    ->where('path', 'like', $oldPath . '%')
                    ->where('id', '!=', $dir->id)
                    ->update([
                        'path'  => DB::raw('REPLACE(path, ' . DB::getPdo()->quote($oldPath) . ', ' . DB::getPdo()->quote($newPath) . ')'),
                        'depth' => DB::raw("depth + {$depthDelta}"),
                    ]);
            }
        });

        $fresh = Directory::find($this->directory->id);

        event(new DirectoryRestored(
            directory:           $fresh,
            relocatedToParentId: $relocated ? $newParentId : null,
            performedBy:         $this->performedBy,
        ));

        return $fresh;
    }

    private function validateNoLiveSiblingCollision(?string $parentId): void
    {
        $exists = Directory::query()
            ->where('parent_id', $parentId)
            ->where('name_normalized', $this->directory->name_normalized)
            ->where('id', '!=', $this->directory->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw RestoreCollisionException::with($this->directory->name, $parentId);
        }
    }
}
