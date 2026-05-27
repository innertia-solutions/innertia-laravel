<?php

namespace Innertia\Files\Directories\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Directories\Events\DirectoryRenamed;
use Innertia\Files\Directories\Exceptions\DuplicateDirectoryNameException;
use Innertia\Files\Directories\Exceptions\InvalidNameException;
use Innertia\Files\Directories\Models\Directory;

class RenameDirectory
{
    public function __construct(
        public readonly Directory $directory,
        public readonly string $newName,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): Directory
    {
        $this->validateName();
        $this->validateNoSiblingCollision();

        $oldName = $this->directory->name;

        $this->directory->name            = $this->newName;
        $this->directory->name_normalized = mb_strtolower($this->newName);
        $this->directory->save();

        event(new DirectoryRenamed($this->directory, $oldName, $this->newName, $this->performedBy));

        return $this->directory;
    }

    private function validateName(): void
    {
        $trimmed = trim($this->newName);
        if ($trimmed === '') {
            throw InvalidNameException::empty();
        }
        if (mb_strlen($this->newName) > 255) {
            throw InvalidNameException::tooLong();
        }
        if (str_contains($this->newName, '/') || str_contains($this->newName, '\\')) {
            throw InvalidNameException::containsSeparator($this->newName);
        }
    }

    private function validateNoSiblingCollision(): void
    {
        $normalized = mb_strtolower($this->newName);

        $exists = Directory::query()
            ->where('parent_id', $this->directory->parent_id)
            ->where('name_normalized', $normalized)
            ->where('id', '!=', $this->directory->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw DuplicateDirectoryNameException::forSibling($this->newName, $this->directory->parent_id);
        }
    }
}
