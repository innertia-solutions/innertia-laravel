<?php

namespace Innertia\Files\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Models\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /**
     * GET /files/{file}
     * Stream the file inline (for images, PDFs viewed in browser).
     */
    public function view(Request $request, string $id): StreamedResponse
    {
        $file = File::findOrFail($id);

        $this->authorize($request, $file);

        return $this->stream($file, 'inline');
    }

    /**
     * GET /files/{file}/download
     * Force-download the file as an attachment.
     */
    public function download(Request $request, string $id): StreamedResponse
    {
        $file = File::findOrFail($id);

        $this->authorize($request, $file);

        return $this->stream($file, 'attachment');
    }

    private function authorize(Request $request, File $file): void
    {
        if ($file->visibility === 'public') {
            return;
        }

        // Own-domain signed URL — the HMAC signature IS the credential, so the
        // file streams without a Bearer token (works in <img>/<iframe>/fetch).
        // Authorization was enforced when the signed URL was issued; expiry keeps
        // the leak window short. A present-but-invalid/expired signature is
        // rejected outright (403); no signature at all falls back to user auth.
        if ($request->hasValidSignature()) {
            return;
        }

        if ($request->query('signature') !== null) {
            abort(403, 'Invalid or expired signature.');
        }

        $user = $request->user();

        if (! $user) {
            abort(401, 'Authentication required.');
        }

        if ($file->visibility === 'auth') {
            return;
        }

        // restricted
        if (! $file->isAccessibleBy($user)) {
            abort(403, 'You do not have permission to access this file.');
        }
    }

    private function stream(File $file, string $disposition): StreamedResponse
    {
        abort_unless(
            Storage::disk($file->disk)->exists($file->path),
            404,
            'File not found in storage.'
        );

        $size     = Storage::disk($file->disk)->size($file->path);
        $mimeType = $file->mime_type ?? 'application/octet-stream';
        $name     = $file->original_name;

        return response()->stream(
            function () use ($file) {
                $stream = Storage::disk($file->disk)->readStream($file->path);
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type'        => $mimeType,
                'Content-Length'      => $size,
                'Content-Disposition' => "{$disposition}; filename=\"{$name}\"",
                'Cache-Control'       => 'no-store, no-cache',
            ]
        );
    }
}
