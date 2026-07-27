<?php

namespace Innertia\Backups\Drivers;

use Innertia\Backups\Contracts\BackupDriver;
use Innertia\Backups\Exceptions\UnsupportedBackupModeException;
use Innertia\Backups\Models\Backup;

class OpenBackupDriver implements BackupDriver
{
    public function create(Backup $backup): void { throw UnsupportedBackupModeException::for('open'); }
    public function restore(Backup $backup): void { throw UnsupportedBackupModeException::for('open'); }
    public function extension(): string { return 'dump'; }
}
