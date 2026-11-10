<?php

namespace Innertia\Backups\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Innertia\Backups\Models\Backup;
use Innertia\Backups\Support\BackupLog;
use Innertia\Backups\Support\BackupSettings;
use Innertia\Backups\UseCases\DeleteBackup;
use Innertia\Backups\UseCases\RestoreBackup;
use Innertia\Backups\UseCases\RunBackup;
use Innertia\Facades\DataTable;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupsController
{
    public function index(Request $request): mixed
    {
        return DataTable::create('backups')
            ->columns(['id', 'status', 'size_bytes', 'driver', 'mode', 'is_safety', 'completed_at', 'created_at'])
            ->render(Backup::query()->where('is_safety', false), $request, 'created_at', 'desc');
    }

    public function store(Request $request): JsonResponse
    {
        // Evita disparar dos dumps concurrentes (doble submit / retry) sobre el mismo disk.
        abort_if(
            Backup::whereIn('status', ['pending', 'running'])->exists(),
            409,
            'A backup is already in progress.',
        );

        $mode = config('innertia.backups.mode', 'app');

        $backup = Backup::create([
            'disk'   => config('innertia.backups.disk', 'backups'),
            // Hoy hay un driver por modo (BackupManager resuelve el driver desde el modo),
            // por eso driver == mode. Se separan cuando exista más de un driver por modo.
            'driver' => $mode,
            'mode'   => $mode,
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

        abort_unless($backup->status === 'completed', 422, 'Backup is not complete.');

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
        abort_if($backup->is_safety, 403, 'Safety backups cannot be deleted manually.');
        (new DeleteBackup($backup))->execute();

        return response()->json(['status' => 'deleted']);
    }

    /**
     * GET platform/backups/settings — política vigente del respaldo automático
     * (siempre activo), defaults y próximas ejecuciones.
     */
    public function settings(): JsonResponse
    {
        return response()->json($this->settingsPayload());
    }

    /** PUT platform/backups/settings — edita horarios y retención desde /platform. */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'times'          => 'required|array|min:1|max:' . BackupSettings::MAX_TIMES,
            'times.*'        => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'timezone'       => 'required|timezone:all',
            'retention_days' => 'required|integer|min:1|max:3650',
            'keep'           => 'required|integer|min:1|max:1000',
        ]);

        BackupSettings::save($data);
        BackupLog::write('info', 'Política de respaldos actualizada.', BackupSettings::all());

        return response()->json($this->settingsPayload());
    }

    private function settingsPayload(): array
    {
        $current = BackupSettings::all();
        $now = Carbon::now($current['timezone']);

        $next = collect($current['times'])
            ->map(fn (string $time) => Carbon::instance(
                (new CronExpression($this->cronFor($time)))->getNextRunDate($now, 0, false, $current['timezone'])
            )->toIso8601String())
            ->sort()
            ->values()
            ->all();

        $last = Backup::query()->where('is_safety', false)->where('status', 'completed')
            ->latest('created_at')->first(['id', 'created_at', 'size_bytes']);

        return $current + [
            'enabled'        => true,
            'defaults'       => BackupSettings::defaults(),
            'next_runs'      => $next,
            'last_completed' => $last,
        ];
    }

    private function cronFor(string $time): string
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return "{$m} {$h} * * *";
    }
}
