<?php

namespace Innertia\Files\Directories\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Innertia\Files\Directories\DirectoriesFeature;
use Innertia\Files\Directories\Events\DirectoryMoved;
use Innertia\Files\Directories\Exceptions\CircularMoveException;
use Innertia\Files\Directories\Exceptions\CrossOwnerMoveException;
use Innertia\Files\Directories\Exceptions\DuplicateDirectoryNameException;
use Innertia\Files\Directories\Exceptions\MaxDepthExceededException;
use Innertia\Files\Directories\Models\Directory;

class MoveDirectory
{
    public function __construct(
        public readonly Directory $directory,
        public readonly ?Directory $newParent,   // null = move to root
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): Directory
    {
        if ($this->newParent !== null) {
            $this->validateNotSelf();
            $this->validateNotIntoDescendant();
            $this->validateSameOwner();
            $this->validateDepth();
        }

        $this->validateNoSiblingCollision();

        $oldParentId = $this->directory->parent_id;

        DB::transaction(function () {
            $oldPath    = $this->directory->path;
            $newPath    = ($this->newParent?->path ?? '/') . $this->directory->id . '/';
            $newDepth   = ($this->newParent?->depth ?? 0) + 1;
            $depthDelta = $newDepth - $this->directory->depth;

            $this->directory->parent_id = $this->newParent?->id;
            $this->directory->path      = $newPath;
            $this->directory->depth     = $newDepth;
            $this->directory->save();

            // Cascade descendants — single UPDATE with REPLACE()
            // Use DB::getPdo()->quote() to safely embed path strings in raw SQL
            $quotedOldPath = DB::getPdo()->quote($oldPath);
            $quotedNewPath = DB::getPdo()->quote($newPath);

            Directory::query()
                ->where('path', 'like', $oldPath . '%')
                ->where('id', '!=', $this->directory->id)
                ->update([
                    'path'  => DB::raw("REPLACE(path, {$quotedOldPath}, {$quotedNewPath})"),
                    'depth' => DB::raw("depth + {$depthDelta}"),
                ]);
        });

        event(new DirectoryMoved(
            directory:   $this->directory,
            oldParentId: $oldParentId,
            newParentId: $this->newParent?->id,
            performedBy: $this->performedBy,
        ));

        return $this->directory;
    }

    private function validateNotSelf(): void
    {
        if ($this->newParent->id === $this->directory->id) {
            throw CircularMoveException::selfMove();
        }
    }

    private function validateNotIntoDescendant(): void
    {
        if (str_starts_with($this->newParent->path, $this->directory->path)) {
            throw CircularMoveException::intoDescendant($this->directory->id, $this->newParent->id);
        }
    }

    private function validateSameOwner(): void
    {
        if ($this->newParent->owner_type !== $this->directory->owner_type
            || $this->newParent->owner_id !== $this->directory->owner_id) {
            throw CrossOwnerMoveException::between(
                "{$this->directory->owner_type}:{$this->directory->owner_id}",
                "{$this->newParent->owner_type}:{$this->newParent->owner_id}",
            );
        }
    }

    private function validateDepth(): void
    {
        $newSelfDepth = $this->newParent->depth + 1;

        // The deepest descendant after move would be at newSelfDepth + (currentMaxDepth - selfDepth)
        $maxDescendantDepth = (int) Directory::query()
            ->where('path', 'like', $this->directory->path . '%')
            ->max('depth') ?: $this->directory->depth;

        $newMaxDepth = $newSelfDepth + ($maxDescendantDepth - $this->directory->depth);
        $allowed     = DirectoriesFeature::maxDepth();

        if ($newMaxDepth > $allowed) {
            throw MaxDepthExceededException::at($newMaxDepth, $allowed);
        }
    }

    private function validateNoSiblingCollision(): void
    {
        $exists = Directory::query()
            ->where('parent_id', $this->newParent?->id)
            ->where('name_normalized', $this->directory->name_normalized)
            ->where('id', '!=', $this->directory->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw DuplicateDirectoryNameException::forSibling(
                $this->directory->name,
                $this->newParent?->id,
            );
        }
    }
}
