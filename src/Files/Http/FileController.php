<?php

namespace Innertia\Files\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Files\Http\Concerns\ServesFiles;
use Innertia\Files\Models\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    use ServesFiles;

    /** GET /files/{id}/view — inline solo para tipos seguros (ver inlineSafe). */
    public function view(Request $request, string $id): StreamedResponse
    {
        $file = $this->resolve($id);
        $this->authorizeFile($request, $file);

        return $this->streamStored(
            $file->disk, $file->path, $file->mime_type, $file->original_name, $file->visibility,
            $this->inlineSafe($file->mime_type, $file->extension) ? 'inline' : 'attachment',
        );
    }

    /** GET /files/{id}/download — siempre attachment. */
    public function download(Request $request, string $id): StreamedResponse
    {
        $file = $this->resolve($id);
        $this->authorizeFile($request, $file);

        return $this->streamStored($file->disk, $file->path, $file->mime_type, $file->original_name, $file->visibility, 'attachment');
    }

    /** Un id no-uuid da 404 limpio (Postgres rechaza uuids inválidos con 22P02). */
    private function resolve(string $id): File
    {
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404, 'File not found.');

        return File::findOrFail($id);
    }
}
