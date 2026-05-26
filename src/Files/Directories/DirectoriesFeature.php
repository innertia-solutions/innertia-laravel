<?php

namespace Innertia\Files\Directories;

class DirectoriesFeature
{
    public static function isActive(): bool
    {
        return (bool) config('innertia.directories.enabled', false);
    }

    public static function modelClass(): string
    {
        return config('innertia.directories.model', Models\Directory::class);
    }

    public static function maxDepth(): int
    {
        return (int) config('innertia.directories.max_depth', 20);
    }

    public static function trashRetentionDays(): ?int
    {
        $v = config('innertia.directories.trash_retention_days');
        return $v === null ? null : (int) $v;
    }
}
