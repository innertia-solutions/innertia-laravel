<?php

namespace Innertia\Files\Directories\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Innertia\Files\Directories\Events\DirectoryHardDeleted;
use Innertia\Files\Directories\Models\Directory;

class HardDeleteDirectory
{
    public function __construct(
        public readonly Directory $directory,
        public readonly bool $cascade = false,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): void
    {
        if ($this->hasLiveDescendants() && ! $this->cascade) {
            throw new \LogicException('Directory has descendants. Pass cascade=true to hard-delete recursively.');
        }

        $id   = $this->directory->id;
        $name = $this->directory->name;

        DB::transaction(function () {
            // Clean entity-level grants for all affected directories
            if ($this->cascade) {
                $affectedIds = Directory::withTrashed()
                    ->where('path', 'like', $this->directory->path . '%')
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->all();
            } else {
                $affectedIds = [(string) $this->directory->id];
            }

            if (Schema::hasTable('entity_permissions')) {
                \Innertia\Auth\RBAC\Models\EntityPermission::where('entity_type', Directory::class)
                    ->whereIn('entity_id', $affectedIds)
                    ->delete();
            }

            if ($this->cascade) {
                Directory::withTrashed()
                    ->where('path', 'like', $this->directory->path . '%')
                    ->forceDelete();
            } else {
                $this->directory->forceDelete();
            }
        });

        event(new DirectoryHardDeleted($id, $name, $this->performedBy));
    }

    private function hasLiveDescendants(): bool
    {
        return Directory::query()
            ->where('path', 'like', $this->directory->path . '%')
            ->where('id', '!=', $this->directory->id)
            ->exists();
    }
}
