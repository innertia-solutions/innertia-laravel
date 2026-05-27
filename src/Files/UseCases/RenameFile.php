<?php

namespace Innertia\Files\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Events\FileRenamed;
use Innertia\Files\Exceptions\InvalidFileNameException;
use Innertia\Files\Models\File;

class RenameFile
{
    public function __construct(
        public readonly File $file,
        public readonly string $newName,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): File
    {
        $this->validate();

        $oldName = $this->file->original_name;
        $this->file->original_name = $this->newName;
        $this->file->save();

        event(new FileRenamed($this->file, $oldName, $this->newName, $this->performedBy));

        return $this->file;
    }

    private function validate(): void
    {
        $trimmed = trim($this->newName);
        if ($trimmed === '') {
            throw InvalidFileNameException::empty();
        }
        if (mb_strlen($this->newName) > 255) {
            throw InvalidFileNameException::tooLong();
        }
        if (str_contains($this->newName, '/') || str_contains($this->newName, '\\')) {
            throw InvalidFileNameException::containsSeparator($this->newName);
        }
    }
}
