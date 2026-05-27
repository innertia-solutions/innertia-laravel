<?php

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Innertia\Platform\Events\EventBusFake;
use Innertia\Platform\Teams\Events\TeamEvent;
use Innertia\Platform\Teams\UseCases\CreateTeam;
use Innertia\Platform\Teams\UseCases\DeleteTeam;
use Innertia\Platform\Teams\UseCases\SyncTeamMembers;
use Innertia\Platform\Teams\UseCases\UpdateTeam;

// Minimal user stub for team_members pivot
class TeamsTestUser extends Model
{
    use HasUuids;

    protected $table    = 'teams_test_users';
    protected $guarded  = [];
    public    $timestamps = false;
}

beforeEach(function () {
    config()->set('innertia.teams.enabled', true);
    config()->set('auth.providers.users.model', TeamsTestUser::class);

    require_once __DIR__ . '/helpers/migrate.php';
    innertiaTeamsMigrateUp();

    Schema::create('teams_test_users', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->string('name')->nullable();
    });
});

afterEach(function () {
    Schema::dropIfExists('teams_test_users');
    innertiaTeamsMigrateDown();
});

it('dispatches TeamCreated on create', function () {
    $fake = EventBusFake::fake();

    (new CreateTeam(tenantId: 'tenant-1', name: 'Engineering'))->execute();

    $fake->assertDispatched(TeamEvent::Created);
});

it('TeamCreated payload contains team fields', function () {
    $fake = EventBusFake::fake();

    (new CreateTeam(tenantId: 'tenant-1', name: 'Engineering'))->execute();

    $fake->assertDispatched(TeamEvent::Created, function ($event) {
        $payload = $event->payload();
        return $payload['name'] === 'Engineering'
            && $payload['organization_id'] === null
            && $payload['parent_team_id'] === null;
    });
});

it('dispatches TeamUpdated on update with changes payload', function () {
    $team = (new CreateTeam(tenantId: 'tenant-1', name: 'Engineering'))->execute();
    $fake = EventBusFake::fake();

    (new UpdateTeam($team->id, name: 'Platform Engineering'))->execute();

    $fake->assertDispatched(TeamEvent::Updated, function ($event) {
        return $event->changes['old']['name'] === 'Engineering'
            && $event->changes['new']['name'] === 'Platform Engineering';
    });
});

it('dispatches TeamDeleted on delete', function () {
    $team = (new CreateTeam(tenantId: 'tenant-1', name: 'Engineering'))->execute();
    $fake = EventBusFake::fake();

    (new DeleteTeam($team->id))->execute();

    $fake->assertDispatched(TeamEvent::Deleted);
});

it('TeamDeleted payload contains team id and name', function () {
    $team = (new CreateTeam(tenantId: 'tenant-1', name: 'Engineering'))->execute();
    $id   = $team->id;
    $fake = EventBusFake::fake();

    (new DeleteTeam($team->id))->execute();

    $fake->assertDispatched(TeamEvent::Deleted, function ($event) use ($id) {
        $payload = $event->payload();
        return $payload['team_id'] === $id
            && $payload['name'] === 'Engineering';
    });
});

it('dispatches TeamMembersSynced with added and removed arrays', function () {
    $team  = (new CreateTeam(tenantId: 'tenant-1', name: 'Engineering'))->execute();
    $user1 = TeamsTestUser::create(['id' => \Illuminate\Support\Str::uuid()]);
    $user2 = TeamsTestUser::create(['id' => \Illuminate\Support\Str::uuid()]);
    $user3 = TeamsTestUser::create(['id' => \Illuminate\Support\Str::uuid()]);

    // Initial members: user1 + user2
    (new SyncTeamMembers($team->id, [
        ['user_id' => $user1->id],
        ['user_id' => $user2->id],
    ]))->execute();

    $fake = EventBusFake::fake();

    // Sync: keep user1, remove user2, add user3
    (new SyncTeamMembers($team->id, [
        ['user_id' => $user1->id],
        ['user_id' => $user3->id],
    ]))->execute();

    $fake->assertDispatched(TeamEvent::MembersSynced, function ($event) use ($user2, $user3) {
        $payload = $event->payload();
        return in_array($user3->id, $payload['added'])
            && in_array($user2->id, $payload['removed'])
            && ! in_array($user3->id, $payload['removed']);
    });
});
