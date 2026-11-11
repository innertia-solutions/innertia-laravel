<?php

namespace Innertia\Files\Versioning;

use Innertia\Files\Models\File;
use Innertia\Files\Models\FileVersion;

final class FileVersionResult
{
    public const CREATED   = 'created';
    public const UNCHANGED = 'unchanged';

    private function __construct(
        public readonly string $status,
        public readonly File $file,
        public readonly ?FileVersion $previous = null,
    ) {}

    public static function created(File $file, FileVersion $previous): self
    {
        return new self(self::CREATED, $file, $previous);
    }

    public static function unchanged(File $file): self
    {
        return new self(self::UNCHANGED, $file);
    }

    public function wasCreated(): bool
    {
        return $this->status === self::CREATED;
    }
}
