<?php

namespace Innertia\Files\Drive\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Files\Directories\DirectoriesFeature;
use Innertia\Files\Drive\DriveSourceResolver;
use Innertia\Files\Drive\Exceptions\DriveException;
use Innertia\Files\Drive\Exceptions\DriveItemNotFoundException;
use Innertia\Files\Drive\Import\DriveDirectoryMapper;
use Innertia\Files\Drive\UseCases\ImportFromDrive;
use Innertia\Files\Drive\UseCases\RefreshFromProvider;
use Innertia\Files\Imports\Enums\FileImportKind;
use Innertia\Files\Imports\Models\FileImport;
use Innertia\Files\Models\File;

/**
 * Crea y consulta procesos de importación/refresh. El trabajo corre en cola
 * (config innertia.files.drive.queue). Hooks para el producto: extraImportRules() e
 * importAttributes() (p. ej. owner_type/owner_id del explorador que importa).
 */
class FileImportsController extends Controller
{
    /** POST files/imports {items[], target_directory_id?, native_format?, visibility?} → 202 */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge([
            'items'               => ['required', 'array', 'min:1', 'max:200'],
            'items.*'             => ['required', 'string', DriveBrowseController::ID_RULE],
            'target_directory_id' => ['nullable', 'uuid'],
            'native_format'       => ['nullable', 'in:office,pdf'],
            'visibility'          => ['nullable', 'in:public,auth,restricted'],
        ], $this->extraImportRules()));

        try {
            $source = DriveSourceResolver::resolve();

            if (! DirectoriesFeature::isActive()) {
                foreach ($data['items'] as $id) {
                    if ($source->metadata($id)->isFolder()) {
                        return response()->json(['message' => 'Importar carpetas requiere el módulo de directorios.'], 422);
                    }
                }
            }
        } catch (DriveItemNotFoundException) {
            // un id inexistente no bloquea: el proceso lo anota como fallido
        } catch (DriveException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! empty($data['target_directory_id'])) {
            $dirClass = DirectoriesFeature::modelClass();
            if (! DirectoriesFeature::isActive() || ! $dirClass::query()->whereKey($data['target_directory_id'])->exists()) {
                return response()->json(['message' => 'La carpeta de destino no existe.'], 422);
            }
        }

        $import = FileImport::create([
            'kind'                => FileImportKind::Import,
            'provider'            => DriveDirectoryMapper::PROVIDER,
            'source_items'        => array_values(array_unique($data['items'])),
            'target_directory_id' => $data['target_directory_id'] ?? null,
            'native_format'       => $data['native_format'] ?? 'office',
            'visibility'          => $data['visibility'] ?? 'auth',
            'file_attributes'     => FileImport::filterAttributes($this->importAttributes($request)),
            'created_by'          => $request->user()?->getAuthIdentifier(),
        ]);

        (new ImportFromDrive($import->id))->onQueue(config('innertia.files.drive.queue'));

        return (new FileImportResource($import->fresh()))->response()->setStatusCode(202);
    }

    /** GET files/imports/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $import = FileImport::find($id);

        if (! $import || ! $this->ownsImport($request, $import)) {
            return response()->json(['message' => 'Import not found.'], 404);
        }

        // Recuperación perezosa: un worker muerto no deja el proceso en `running` para siempre.
        $import->markStaleAsFailed();

        return (new FileImportResource($import))->response();
    }

    /** POST files/{id}/refresh → 202 con el proceso de refresh */
    public function refresh(Request $request, string $id): JsonResponse
    {
        $file = File::withTrashed()->find($id);

        if (! $file) {
            return response()->json(['message' => 'File not found.'], 404);
        }
        if ($file->trashed()) {
            return response()->json(['message' => 'El archivo está en la papelera.'], 422);
        }
        if ($request->user() && ! $file->isAccessibleBy($request->user())) {
            return response()->json(['message' => 'You do not have permission to access this file.'], 403);
        }
        if ($file->provider !== DriveDirectoryMapper::PROVIDER || ! $file->external_id) {
            return response()->json(['message' => 'El archivo no fue importado desde Google Drive.'], 422);
        }
        if (! DriveSourceResolver::available()) {
            return response()->json(['message' => 'Google Drive no está configurado.'], 422);
        }

        $import = FileImport::create([
            'kind'         => FileImportKind::Refresh,
            'provider'     => DriveDirectoryMapper::PROVIDER,
            'source_items' => [$file->external_id],
            'file_id'      => $file->id,
            'created_by'   => $request->user()?->getAuthIdentifier(),
        ]);

        (new RefreshFromProvider($import->id))->onQueue(config('innertia.files.drive.queue'));

        return (new FileImportResource($import->fresh()))->response()->setStatusCode(202);
    }

    // ── Extension hooks ──────────────────────────────────────────────────────

    protected function extraImportRules(): array
    {
        return [];
    }

    /** Atributos a aplicar a cada File/Directory creado (solo owner_type/owner_id se respetan). */
    protected function importAttributes(Request $request): array
    {
        return [];
    }

    /** Un usuario solo ve sus propios procesos (sobrescribir para administradores). */
    protected function ownsImport(Request $request, FileImport $import): bool
    {
        $user = $request->user();

        return $user === null || $import->created_by === null || $import->created_by === (string) $user->getAuthIdentifier();
    }
}
