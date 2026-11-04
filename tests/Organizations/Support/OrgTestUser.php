<?php

namespace Innertia\Tests\Organizations\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Innertia\Auth\RBAC\Traits\HasContexts;
use Innertia\Auth\RBAC\Traits\HasRoles;

class OrgTestUser extends Authenticatable
{
    use HasUuids, HasRoles, HasContexts;

    protected $table = 'test_users';
    protected $guarded = [];
    public $timestamps = false;
}
