<?php

namespace Innertia\Backups\Drivers;

use Illuminate\Support\Facades\Storage;
use Innertia\Backups\Contracts\BackupDriver;
use Innertia\Backups\Contracts\ProcessRunner;
use Innertia\Backups\Models\Backup;
use RuntimeException;

class AppBackupDriver implements BackupDriver
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function extension(): string
    {
        return 'dump';
    }

    public function create(Backup $backup): void
    {
        $conn = config('database.connections.pgsql');
        $tmp  = tempnam(sys_get_temp_dir(), 'ibkp_') . '.dump';

        $result = $this->runner->run(
            command: [
                'pg_dump', '-Fc',
                '-h', (string) $conn['host'],
                '-p', (string) $conn['port'],
                '-U', (string) $conn['username'],
                '-d', (string) $conn['database'],
                '-f', $tmp,
            ],
            env: ['PGPASSWORD' => (string) ($conn['password'] ?? '')],
        );

        if (! $result->successful()) {
            @unlink($tmp);
            throw new RuntimeException($result->errorOutput() ?: 'pg_dump failed');
        }

        $contents = file_get_contents($tmp);
        $path = "backups/{$backup->id}.{$this->extension()}";
        Storage::disk($backup->disk)->put($path, $contents);
        @unlink($tmp);

        $backup->update([
            'path'       => $path,
            'size_bytes' => strlen($contents),
            'checksum'   => hash('sha256', $contents),
        ]);
    }

    public function restore(Backup $backup): void
    {
        $conn = config('database.connections.pgsql');
        $tmp  = tempnam(sys_get_temp_dir(), 'ibkp_') . '.dump';
        file_put_contents($tmp, Storage::disk($backup->disk)->get($backup->path));

        $result = $this->runner->run(
            command: [
                'pg_restore', '--clean', '--if-exists',
                '-h', (string) $conn['host'],
                '-p', (string) $conn['port'],
                '-U', (string) $conn['username'],
                '-d', (string) $conn['database'],
                $tmp,
            ],
            env: ['PGPASSWORD' => (string) ($conn['password'] ?? '')],
        );
        @unlink($tmp);

        if (! $result->successful()) {
            throw new RuntimeException($result->errorOutput() ?: 'pg_restore failed');
        }
    }
}
