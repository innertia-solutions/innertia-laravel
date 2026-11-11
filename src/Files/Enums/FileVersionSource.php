<?php

namespace Innertia\Files\Enums;

enum FileVersionSource: string
{
    case Upload          = 'upload';            // subida inicial
    case Replace         = 'replace';           // reemplazo manual
    case ProviderImport  = 'provider_import';   // importado desde un proveedor (Drive)
    case ProviderRefresh = 'provider_refresh';  // "Actualizar desde Drive"
    case Restore         = 'restore';           // restaurada desde una versión anterior
}
