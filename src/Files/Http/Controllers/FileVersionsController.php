<?php

namespace Innertia\Files\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Exceptions\FileTrashedException;
use Innertia\Files\Exceptions\FileVersionNotFoundException;
use Innertia\Files\Exceptions\FileVersioningNotMigratedException;
use Innertia\Files\Http\Resources\FileResource;
use Innertia\Files\Http\Resources\FileVersionResource;
use Innertia\Files\Models\File;
use Innertia\Files\Support\FilesLog;
use Innertia\Files\UseCases\ReplaceFileContents;
use Innertia\Files\UseCases\RestoreFileVersion;
use Innertia\Files\Versioning\FileVersionResult;

class FileVersionsController extends Controller
{
    /** GET files/{id}/versions — vigente primero, luego el historial (desc). */
    public function index(Request $request, string $id): JsonResponse
    {
        $file = $this->findAccessible($request, $id);

        $entries = collect([FileVersionResource::fromCurrent($file)])
            ->concat($file->versions()->orderByDesc('version')->get()->map(fn ($v) => new FileVersionResource($v)));

        return response()->json(['data' => $entries->map->toArray($request)->values()]);
    }

    /** POST files/{id}/versions/{version}/restore */
    public function restore(Request $request, string $id, int $version): JsonResponse
    {
        $file = $this->findAccessible($request, $id);

        return $this->respond($request, $file, fn () => (new RestoreFileVersion($file, $version, $request->user()))->execute());
    }

    /** POST files/{id}/contents (multipart `file`) — reemplazo manual; el nombre no cambia. */
    public function replaceContents(Request $request, string $id): JsonResponse
    {
        $maxKb = (int) config('innertia.files.max_upload_mb', 100) * 1024;
        $request->validate(['file' => ['required', 'file', "max:{$maxKb}"]]);

        $file = $this->findAccessible($request, $id);
        $uploaded = $request->file('file');

        return $this->respond($request, $file, fn () => (new ReplaceFileContents(
            file:        $file,
            localPath:   $uploaded->getRealPath(),
            source:      FileVersionSource::Replace,
            mimeType:    $uploaded->getMimeType(),
            extension:   $uploaded->getClientOriginalExtension() ?: $file->extension,
            performedBy: $request->user(),
        ))->execute());
    }

    private function respond(Request $request, File $file, \Closure $run): JsonResponse
    {
        try {
            /** @var FileVersionResult $result */
            $result = $run();
        } catch (FileVersionNotFoundException|ModelNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (FileTrashedException|FileVersioningNotMigratedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            FilesLog::warning('files.versioning: error en API de versiones', [
                'error'   => $e->getMessage(),
                'file_id' => $file->id,
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $result->file->loadCount('versions');

        return response()->json([
            'status' => $result->status,
            'file'   => (new FileResource($result->file))->toArray($request),
        ]);
    }

    /**
     * Incluye la papelera a propósito: así restore/replace responden 422
     * (FileTrashedException) en vez de 404, y el historial se puede consultar.
     */
    private function findAccessible(Request $request, string $id): File
    {
        abort_unless(Str::isUuid($id), 404, 'File not found.');

        $file = File::withTrashed()->find($id);
        abort_unless($file !== null, 404, 'File not found.');

        if ($request->user() && ! $file->isAccessibleBy($request->user())) {
            abort(403, 'You do not have permission to access this file.');
        }

        return $file;
    }
}
