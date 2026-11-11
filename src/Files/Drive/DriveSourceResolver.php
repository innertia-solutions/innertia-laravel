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

    private const AVAILABLE_KEY = 'innertia.files.drive.available';

    /**
     * ¿Hay una fuente de Drive utilizable? Nunca lanza: una fuente ligada que no se puede
     * construir cuenta como no disponible. Se memoiza por request (atributo del Request, no
     * estático: seguro en Octane) porque FileResource lo consulta por cada archivo listado.
     */
    public static function available(): bool
    {
        $request = app()->bound('request') ? app('request') : null;

        if ($request !== null && $request->attributes->has(self::AVAILABLE_KEY)) {
            return (bool) $request->attributes->get(self::AVAILABLE_KEY);
        }

        try {
            self::resolve();
            $available = true;
        } catch (\Throwable) {
            $available = false;
        }

        $request?->attributes->set(self::AVAILABLE_KEY, $available);

        return $available;
    }

    /** Olvida la disponibilidad memoizada en el request actual (tests o cambio de credencial). */
    public static function flush(): void
    {
        if (app()->bound('request')) {
            app('request')->attributes->remove(self::AVAILABLE_KEY);
        }
    }
}
