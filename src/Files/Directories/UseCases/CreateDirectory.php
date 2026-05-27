<?php

namespace Innertia\Files\Directories\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Innertia\Files\Directories\DirectoriesFeature;
use Innertia\Files\Directories\Events\DirectoryCreated;
use Innertia\Files\Directories\Exceptions\DuplicateDirectoryNameException;
use Innertia\Files\Directories\Exceptions\InvalidNameException;
use Innertia\Files\Directories\Exceptions\MaxDepthExceededException;
use Innertia\Files\Directories\Exceptions\ParentTrashedException;
use Innertia\Files\Directories\Models\Directory;

class CreateDirectory
{
    public function __construct(
        public readonly ?Directory $parent,
        public readonly string $name,
        public readonly ?Model $owner = null,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): Directory
    {
        $this->validateName();

        if ($this->parent !== null) {
            $this->validateParentNotTrashed();
            $this->validateDepth();
        }

        $this->validateNoSiblingCollision();

        return DB::transaction(function () {
            $dir = new Directory([
                'name'            => $this->name,
                'name_normalized' => mb_strtolower($this->name),
                'parent_id'       => $this->parent?->id,
                'depth'           => $this->parent ? $this->parent->depth + 1 : 1,
                'path'            => '/',  // overwritten after save() to get the UUID
                'owner_type'      => $this->owner ? $this->owner::class : $this->parent?->owner_type,
                'owner_id'        => $this->owner?->getKey() ?? $this->parent?->owner_id,
                'created_by'      => $this->performedBy?->getAuthIdentifier(),
            ]);
            $dir->save();

            $dir->path = ($this->parent?->path ?? '/') . $dir->id . '/';
            $dir->save();

            event(new DirectoryCreated($dir, $this->performedBy));

            return $dir;
        });
    }

    private function validateName(): void
    {
        $trimmed = trim($this->name);
        if ($trimmed === '') {
            throw InvalidNameException::empty();
        }
        if (mb_strlen($this->name) > 255) {
            throw InvalidNameException::tooLong();
        }
        if (str_contains($this->name, '/') || str_contains($this->name, '\\')) {
            throw InvalidNameException::containsSeparator($this->name);
        }
    }

    private function validateParentNotTrashed(): void
    {
        if ($this->parent->trashed()) {
            throw ParentTrashedException::for($this->parent->id);
        }
    }

    private function validateDepth(): void
    {
        $newDepth = $this->parent->depth + 1;
        $max = DirectoriesFeature::maxDepth();
        if ($newDepth > $max) {
            throw MaxDepthExceededException::at($newDepth, $max);
        }
    }

    private function validateNoSiblingCollision(): void
    {
        $normalized = mb_strtolower($this->name);

        $exists = Directory::query()
            ->where('parent_id', $this->parent?->id)
            ->where('name_normalized', $normalized)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw DuplicateDirectoryNameException::forSibling($this->name, $this->parent?->id);
        }
    }
}
