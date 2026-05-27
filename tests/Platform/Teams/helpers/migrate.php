<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function innertiaTeamsMigrateUp(): void
{
    Schema::create('teams', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->string('tenant_id')->nullable();
        $t->unsignedBigInteger('organization_id')->nullable();
        $t->uuid('parent_team_id')->nullable();
        $t->string('name');
        $t->string('description')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });

    Schema::create('team_members', function (Blueprint $t) {
        $t->uuid('team_id');
        $t->uuid('user_id');
        $t->string('role_in_team')->default('member');
        $t->timestamp('joined_at')->useCurrent();
        $t->primary(['team_id', 'user_id']);
    });
}

function innertiaTeamsMigrateDown(): void
{
    Schema::dropIfExists('team_members');
    Schema::dropIfExists('teams');
}
