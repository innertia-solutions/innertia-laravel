<?php

namespace Innertia\Files\Drive;

use Innertia\Files\Drive\Exceptions\DriveNotConfiguredException;

/**
 * Elige la fuente de Drive: una implementación ligada en el container (producto o tests)
 * o, si no hay, la cuenta de servicio de Workspace.
 */
final class DriveSourceResolver
{
    public static function resolve(): DriveSource
    {
        if (app()->bound(DriveSource::class)) {
            return app(DriveSource::class);
        }

        if (ServiceAccountDriveSource::hasCredentials()) {
            return app(ServiceAccountDriveSource::class);
        }

        throw new DriveNotConfiguredException();
    }

    public static function available(): bool
    {
        try {
            self::resolve();

            return true;
        } catch (DriveNotConfiguredException) {
            return false;
        }
    }
}
