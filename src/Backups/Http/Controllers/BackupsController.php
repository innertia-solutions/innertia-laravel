<?php

namespace Innertia\Backups\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Innertia\Backups\Models\Backup;
use Innertia\Backups\UseCases\DeleteBackup;
use Innertia\Backups\UseCases\RestoreBackup;
use Innertia\Backups\UseCases\RunBackup;
use Innertia\Facades\DataTable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupsController
{
    public function index(Request $request): mixed
    {
        return DataTable::create('backups')
            ->columns(['id', 'status', 'size_bytes', 'driver', 'mode', 'is_safety', 'completed_at', 'created_at'])
            ->render(Backup::query(), $request, 'created_at', 'desc');
    }

    public function store(Request $request): JsonResponse
    {
        $backup = Backup::create([
            'disk'   => config('innertia.backups.disk', 'backups'),
            'driver' => config('innertia.backups.mode', 'app'),
            'mode'   => config('innertia.backups.mode', 'app'),
            'status' => 'pending',
        ]);

        (new RunBackup($backup))->onQueue();

        return response()->json($backup, 201);
    }

    public function download(string $id): StreamedResponse
    {
        $backup = Backup::findOrFail($id);

        abort_unless(
            $backup->path && Storage::disk($backup->disk)->exists($backup->path),
            404,
        );

        return Storage::disk($backup->disk)->download($backup->path, "backup-{$backup->id}.dump");
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $backup = Backup::findOrFail($id);

        // Confirmation token = DB name (predictable, validated server-side).
        $request->validate([
            'confirmation' => 'required|in:' . config('database.connections.pgsql.database'),
        ]);

        (new RestoreBackup($backup))->onQueue();

        return response()->json(['status' => 'restoring'], 202);
    }

    public function destroy(string $id): JsonResponse
    {
        $backup = Backup::findOrFail($id);
        (new DeleteBackup($backup))->execute();

        return response()->json(['status' => 'deleted']);
    }
}
