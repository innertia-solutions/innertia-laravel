<?php

namespace Innertia\Backups\Drivers;

use Innertia\Backups\Contracts\BackupDriver;
use Innertia\Backups\Exceptions\UnsupportedBackupModeException;
use Innertia\Backups\Models\Backup;

class SaasBackupDriver implements BackupDriver
{
    public function create(Backup $backup): void { throw UnsupportedBackupModeException::for('saas'); }
    public function restore(Backup $backup): void { throw UnsupportedBackupModeException::for('saas'); }
    public function extension(): string { return 'dump'; }
}
