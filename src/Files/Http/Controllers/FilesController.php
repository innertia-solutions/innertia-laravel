<?php

namespace Innertia\Files\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Innertia\Files\Exceptions\DirectoriesFeatureDisabledException;
use Innertia\Files\Exceptions\InvalidFileNameException;
use Innertia\Files\Exceptions\OrphanedFileRestoreException;
use Innertia\Files\Http\Resources\FileResource;
use Innertia\Files\Models\File;
use Innertia\Files\UseCases\EmptyFilesTrash;
use Innertia\Files\UseCases\HardDeleteFile;
use Innertia\Files\UseCases\UploadFile;

class FilesController extends Controller
{
    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = File::query()->orderBy('created_at', 'desc');

        // Trashed filter
        $trashed = $request->query('trashed');
        if ($trashed === 'only') {
            $query->onlyTrashed();
        } elseif ($trashed === 'with') {
            $query->withTrashed();
        } else {
            $query->whereNull('deleted_at');
        }

        if ($search = $request->query('search')) {
            $query->where('original_name', 'like', '%' . $search . '%');
        }

        if ($ownerType = $request->query('owner_type')) {
            $query->where('owner_type', $ownerType);
        }

        if ($ownerId = $request->query('owner_id')) {
            $query->where('owner_id', $ownerId);
        }

        if ($request->query('include') === 'tags') {
            $query->with('tags');
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        return FileResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, string $id): FileResource|JsonResponse
    {
        $file = File::withTrashed()->find($id);

        if (! $file) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        if ($request->query('include') === 'tags') {
            $file->load('tags');
        }

        return new FileResource($file);
    }

    public function store(Request $request): JsonResponse
    {
        $rules = array_merge([
            'file'       => ['required', 'file'],
            'visibility' => ['sometimes', 'in:public,auth,restricted'],
            'owner_type' => ['nullable', 'string'],
            'owner_id'   => ['nullable', 'uuid'],
            'directory_id' => ['nullable', 'uuid'],
        ], $this->extraStoreRules());

        $request->validate($rules);

        $uploadedFile = $request->file('file');
        $visibility   = $request->input('visibility', 'auth');
        $directoryId  = $request->input('directory_id');
        $ownerType    = $request->input('owner_type');
        $ownerId      = $request->input('owner_id');

        // Build owner model stub if owner_type/owner_id provided
        $owner = null;
        if ($ownerType && $ownerId) {
            $owner = new class($ownerType, $ownerId) extends \Illuminate\Database\Eloquent\Model {
                public function __construct(private string $ownerType, private ?string $ownerId)
                {
                    parent::__construct([]);
                }

                public function getMorphClass(): string { return $this->ownerType; }
                public function getKey(): mixed { return $this->ownerId; }
            };
        }

        $file = (new UploadFile(
            uploaded:    $uploadedFile,
            directoryId: $directoryId,
            owner:       $owner,
            visibility:  $visibility,
            performedBy: auth()->user(),
        ))->execute();

        // Set owner_type/owner_id directly since stub class won't serialize correctly
        if ($ownerType && $ownerId) {
            $file->owner_type = $ownerType;
            $file->owner_id   = $ownerId;
            $file->save();
        }

        $extra = $this->extraFields($request, $file);
        if (! empty($extra)) {
            $file->forceFill($extra)->save();
        }

        return (new FileResource($file->fresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $rules = array_merge([
            'original_name' => ['sometimes', 'string', 'max:255'],
            'directory_id'  => ['sometimes', 'nullable', 'uuid'],
            'visibility'    => ['sometimes', 'in:public,auth,restricted'],
        ], $this->extraUpdateRules());

        $request->validate($rules);

        $file = File::withTrashed()->find($id);

        if (! $file) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        try {
            if ($request->has('original_name') && $request->input('original_name') !== $file->original_name) {
                $file = $file->rename($request->input('original_name'));
            }

            if ($request->has('directory_id')) {
                if ($request->input('directory_id') === null) {
                    $file->moveToRoot();
                } else {
                    $directoryClass = \Innertia\Files\Directories\DirectoriesFeature::modelClass();
                    $dir = $directoryClass::find($request->input('directory_id'));
                    if (! $dir) {
                        abort(422, 'Directory not found.');
                    }
                    $file->moveTo($dir);
                }
            }

        } catch (InvalidFileNameException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (DirectoriesFeatureDisabledException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($request->has('visibility')) {
            $file->forceFill(['visibility' => $request->input('visibility')])->save();
        }

        $extra = $this->extraFields($request, $file);
        if (! empty($extra)) {
            $file->forceFill($extra)->save();
        }

        return (new FileResource($file->fresh()))->response();
    }

    /**
     * GET /files/{id}/share-link?hours=N[&download=1]
     * Genera una URL firmada de dominio propio para compartir el archivo por N
     * horas (default 24). La firma es la credencial: quien abra el link accede
     * sin login hasta que expire. Con download=1 apunta a la ruta de descarga.
     */
    public function shareLink(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'hours'    => ['sometimes', 'integer', 'min:1', 'max:8760'],
            'download' => ['sometimes', 'boolean'],
        ]);

        $file = File::find($id);

        if (! $file) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $hours   = (int) ($data['hours'] ?? 24);
        $minutes = $hours * 60;

        $url = $request->boolean('download')
            ? $file->signedDownloadUrl($minutes)
            : $file->signedViewUrl($minutes);

        return response()->json(['url' => $url, 'expires_in_hours' => $hours]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $file = File::withTrashed()->find($id);

        if (! $file) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        if ($request->boolean('force')) {
            (new HardDeleteFile($file, auth()->user()))->execute();
        } else {
            $file->trash();
        }

        return response()->json(null, 204);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $file = File::onlyTrashed()->find($id);

        if (! $file) {
            return response()->json(['message' => 'File not found or not in trash.'], 404);
        }

        $directoryId = $request->input('directory_id');

        try {
            $restored = $file->restoreFromTrash($directoryId);
        } catch (OrphanedFileRestoreException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new FileResource($restored))->response();
    }

    public function trash(Request $request): AnonymousResourceCollection
    {
        $query = File::onlyTrashed()->orderBy('deleted_at', 'desc');

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        return FileResource::collection($query->paginate($perPage));
    }

    public function emptyTrash(): JsonResponse
    {
        $count = (new EmptyFilesTrash())->execute();

        return response()->json(['deleted' => $count]);
    }

    // ── Extension hooks (template method) ────────────────────────────────────

    protected function extraStoreRules(): array { return []; }
    protected function extraUpdateRules(): array { return []; }
    protected function extraFields(Request $request, ?File $file = null): array { return []; }
}
