<?php

namespace Innertia\Backups\Contracts;

use Innertia\Backups\Models\Backup;

interface BackupDriver
{
    /** Genera el artefacto y setea path/size_bytes/checksum en el registro. Lanza en fallo. */
    public function create(Backup $backup): void;

    /** Aplica el artefacto sobre el entorno actual. Lanza en fallo. */
    public function restore(Backup $backup): void;

    /** Extensión del artefacto (sin punto). */
    public function extension(): string;
}
