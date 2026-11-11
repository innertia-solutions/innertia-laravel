<?php
namespace Innertia\Files\Drive\Import;

use Illuminate\Auth\GenericUser;
use Innertia\Files\Directories\Exceptions\DuplicateDirectoryNameException;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Directories\UseCases\CreateDirectory;
use Innertia\Files\Drive\DriveItem;
use Innertia\Files\Imports\Models\FileImport;

/**
 * Carpeta de Drive → Directory. Reimportar reutiliza la carpeta ya vinculada (provider +
 * external_id bajo el mismo padre y del mismo dueño). Si un hermano no vinculado ya usa el
 * nombre, agrega " (2)", " (3)"… (Drive permite nombres repetidos; Directories no).
 *
 * Dueño: si el import trae owner_type/owner_id se aplica a TODA carpeta creada; si no, la
 * carpeta hereda el del padre (raíz: sin dueño).
 */
final class DriveDirectoryMapper
{
    public const PROVIDER = 'google_drive';
    private const MAX_SUFFIX = 50;

    public function ensure(DriveItem $folder, ?Directory $parent, FileImport $import): Directory
    {
        $owner = self::ownerFor($parent, $import);

        $existing = $owner->scope(Directory::query())
            ->where('provider', self::PROVIDER)
            ->where('external_id', $folder->id)
            ->where('parent_id', $parent?->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $base = self::sanitize($folder->name);
        $by = $import->created_by !== null ? new GenericUser(['id' => $import->created_by]) : null;
        $dir = null;

        for ($n = 1; $n <= self::MAX_SUFFIX && $dir === null; $n++) {
            $name = $n === 1 ? $base : mb_substr($base, 0, 240) . " ({$n})";

            try {
                $dir = (new CreateDirectory(parent: $parent, name: $name, owner: $owner->model(), performedBy: $by))->execute();
            } catch (DuplicateDirectoryNameException) {
                // probar el siguiente sufijo
            }
        }

        if ($dir === null) {
            throw new \RuntimeException("No se pudo crear la carpeta \"{$base}\": demasiados nombres repetidos.");
        }

        $dir->forceFill(['provider' => self::PROVIDER, 'external_id' => $folder->id] + $owner->attributes())->save();

        return $dir;
    }

    /** Dueño con que se busca/crea la carpeta: el del import si lo trae, si no el heredado del padre. */
    public static function ownerFor(?Directory $parent, FileImport $import): ImportOwner
    {
        $attributes = FileImport::filterAttributes($import->file_attributes ?? []);

        return ImportOwner::from($attributes !== [] ? $attributes : [
            'owner_type' => $parent?->owner_type,
            'owner_id'   => $parent?->owner_id,
        ]);
    }

    public static function sanitize(string $name): string
    {
        $clean = trim(str_replace(['/', '\\'], '-', $name));

        return $clean === '' ? 'Sin nombre' : mb_substr($clean, 0, 255);
    }
}
