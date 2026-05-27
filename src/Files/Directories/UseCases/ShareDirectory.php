<?php

namespace Innertia\Files\Directories\UseCases;

use Illuminate\Database\Eloquent\Model;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Platform\Contracts\UseCase;

class ShareDirectory extends UseCase
{
    public function __construct(
        public readonly Directory $directory,
        public readonly Model    $grantable,
        public readonly string   $action = 'access',
    ) {}

    public function execute(): EntityPermission
    {
        return EntityPermission::grant($this->directory, $this->grantable, $this->action);
    }
}
