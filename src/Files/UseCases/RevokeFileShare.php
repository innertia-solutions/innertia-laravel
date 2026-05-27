<?php

namespace Innertia\Files\UseCases;

use Illuminate\Database\Eloquent\Model;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Files\Models\File;
use Innertia\Platform\Contracts\UseCase;

class RevokeFileShare extends UseCase
{
    public function __construct(
        public readonly File   $file,
        public readonly Model  $grantable,
        public readonly string $action = 'view',
    ) {}

    public function execute(): void
    {
        EntityPermission::revoke($this->file, $this->grantable, $this->action);
    }
}
