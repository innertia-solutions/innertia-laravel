<?php

namespace Innertia\Workspace;

use Innertia\Workspace\Contracts\WorkspaceProvider;
use Innertia\Workspace\Providers\GoogleWorkspaceProvider;

class WorkspaceManager
{
    /** Provider activo. Hoy: Google (único implementado). Futuro: elegir por Settings('workspace.provider'). */
    public function provider(): WorkspaceProvider
    {
        return app(GoogleWorkspaceProvider::class);
    }

    public function configured(): bool
    {
        return $this->provider()->configured();
    }
}
