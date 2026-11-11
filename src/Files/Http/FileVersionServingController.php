<?php

namespace Innertia\Files\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Innertia\Files\Http\Concerns\ServesFiles;
use Innertia\Files\Models\File;
use Innertia\Files\Models\FileVersion;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /files/{id}/versions/{version}/view|download — la versión hereda el acceso
 * de su archivo. {version} igual al número vigente sirve la fila files.
 */
class FileVersionServingController extends Controller
{
    use ServesFiles;

    public function view(Request $request, string $id, int $version): StreamedResponse
    {
        [$file, $target] = $this->resolve($id, $version);
        $this->authorizeFile($request, $file);

        return $this->streamStored(
            $target->disk, $target->path, $target->mime_type, $target->original_name, $file->visibility,
            $this->inlineSafe($target->mime_type, $target->extension) ? 'inline' : 'attachment',
        );
    }

    public function download(Request $request, string $id, int $version): StreamedResponse
    {
        [$file, $target] = $this->resolve($id, $version);
        $this->authorizeFile($request, $file);

        return $this->streamStored($target->disk, $target->path, $target->mime_type, $target->original_name, $file->visibility, 'attachment');
    }

    /** @return array{0: File, 1: File|FileVersion} */
    private function resolve(string $id, int $version): array
    {
        abort_unless(Str::isUuid($id), 404, 'File not found.');

        $file = File::findOrFail($id);

        if ($version === (int) $file->version) {
            return [$file, $file];
        }

        $target = FileVersion::where('file_id', $file->id)->where('version', $version)->first();
        abort_unless($target !== null, 404, 'Version not found.');

        return [$file, $target];
    }
}
