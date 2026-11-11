<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Drive\DriveSource;
use Innertia\Tests\Files\Drive\Support\FakeDriveSource;

if (! defined('DOCX')) {
    define('DOCX', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
}
if (! defined('XLSX')) {
    define('XLSX', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}

/** Arranque común: Files + Directories migrados, disk falso y un Drive en memoria ligado. */
function driveTestBoot(): FakeDriveSource
{
    config()->set('innertia.mode', 'app');
    config()->set('innertia.directories.enabled', true);
    require_once __DIR__ . '/../helpers/migrate.php';
    require_once __DIR__ . '/../Directories/helpers/migrate.php';
    innertiaFilesMigrateUp();
    innertiaDirectoriesMigrateUp();
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $fake = new FakeDriveSource();
    app()->instance(DriveSource::class, $fake);

    return $fake;
}

function driveTestShutdown(): void
{
    app()->offsetUnset(DriveSource::class);
    innertiaDirectoriesMigrateDown();
    innertiaFilesMigrateDown();
}
