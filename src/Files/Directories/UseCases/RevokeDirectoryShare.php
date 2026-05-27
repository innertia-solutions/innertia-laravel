<?php

namespace Innertia\Files\Directories\UseCases;

use Illuminate\Database\Eloquent\Model;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Platform\Contracts\UseCase;

class RevokeDirectoryShare extends UseCase
{
    public function __construct(
        public readonly Directory $directory,
        public readonly Model    $grantable,
        public readonly string   $action = 'view',
    ) {}

    public function execute(): void
    {
        EntityPermission::revoke($this->directory, $this->grantable, $this->action);
    }
}
