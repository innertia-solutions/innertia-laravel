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

        // Fix A: release the placeholder file created by tempnam before appending the extension,
        // otherwise a zero-byte orphan is left on every call.
        $base = tempnam(sys_get_temp_dir(), 'ibkp_');
        @unlink($base);
        $tmp = $base . '.dump';

        $result = $this->runner->run(
            command: [
                'pg_dump', '-Fc', '--no-owner', '--no-acl',
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

        // Fix B: guard the artifact read so a missing/unreadable file does not produce
        // a silent 0-byte "completed" backup. The @ suppresses the PHP warning that
        // file_get_contents emits when the file does not exist; we handle the failure
        // ourselves by checking for false.
        $contents = @file_get_contents($tmp);
        if ($contents === false) {
            @unlink($tmp);
            throw new RuntimeException('pg_dump reported success but the artifact is unreadable: ' . $tmp);
        }

        $path = "backups/{$backup->id}.{$this->extension()}";
        Storage::disk($backup->disk)->put($path, $contents);
        @unlink($tmp);

        // size_bytes and checksum are computed from the in-memory artifact buffer
        // (valid for local/S3 disks that store bytes verbatim).
        $backup->update([
            'path'       => $path,
            'size_bytes' => strlen($contents),
            'checksum'   => hash('sha256', $contents),
        ]);
    }

    public function restore(Backup $backup): void
    {
        $conn = config('database.connections.pgsql');

        // Fix A: release the placeholder file created by tempnam before appending the extension.
        $base = tempnam(sys_get_temp_dir(), 'ibkp_');
        @unlink($base);
        $tmp = $base . '.dump';

        // Fix C: guard the artifact fetch so a missing or unreadable path is reported clearly.
        $artifact = Storage::disk($backup->disk)->get($backup->path);
        if ($artifact === null) {
            throw new RuntimeException("Backup artifact not found on disk [{$backup->disk}]: {$backup->path}");
        }
        if (file_put_contents($tmp, $artifact) === false) {
            @unlink($tmp);
            throw new RuntimeException('Failed to write restore artifact to temp path: ' . $tmp);
        }

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
