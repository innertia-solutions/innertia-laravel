---
name: innertia-teams
description: Use when working with Innertia Teams — RBAC por grupo de Users. Trigger for mentions of teams, team members, team_members table, parent_team_id hierarchy, HasTeams trait, role_in_team (member/lead), or rolesViaTeams/permissionsViaTeams.
---

# Innertia Teams — RBAC por grupo de Users

Teams agrupan **Users** para asignación colectiva de roles y permisos. Un User en el team hereda todos los roles del team. Soporta jerarquía via `parent_team_id` y org-scoping cuando Organizations está activo.

**Importante**: en este paquete, "Team" es un concepto de **autorización**, no de RR.HH. Si tu producto tiene una entidad operacional de personas (ej. Worker = empleado), va en tu propio dominio y se relaciona al User. El Team del paquete es Users + roles.

## Activación

```bash
# .env
INNERTIA_TEAMS_ENABLED=true

# config/innertia.php
'teams' => [
    'enabled' => env('INNERTIA_TEAMS_ENABLED', false),
    'model'   => \Innertia\Platform\Teams\Models\Team::class,
],
```

```bash
php artisan innertia:teams:install   # genera migration
php artisan migrate
```

Crea:
- `teams (id uuid PK, tenant_id, organization_id NULLABLE, parent_team_id, name, description, soft deletes)`
- `team_members (team_id, user_id, role_in_team [member|lead], joined_at)` — composite PK

## Trait HasTeams

Ya viene aplicado automáticamente en `\Innertia\Auth\Models\User` (base). Si tu User NO extiende la base, agrégalo manualmente:

```php
use Innertia\Platform\Teams\Traits\HasTeams;

class User extends Authenticatable {
    use HasTeams;
}
```

Cuando TeamsFeature está disabled el trait es no-op (cero overhead).

Métodos expuestos:

```php
$user->teams()                  // BelongsToMany
$user->teamIds()                // array<string>
$user->rolesViaTeams()          // Collection<Role>  — roles heredados de teams
$user->permissionsViaTeams()    // array<string>     — permisos consolidados
```

## Combinaciones Orgs ↔ Teams

| Orgs | Teams | Resultado |
|---|---|---|
| OFF | OFF | Solo users + roles individuales |
| OFF | ON | Teams tenant-wide. Default para SaaS pequeño con grupos de permisos |
| ON | OFF | Multi-org con users sueltos. Cada user permisos individuales |
| ON | ON | Enterprise. Teams pueden ser tenant-wide (`organization_id = NULL`) o por org |

## Asignar roles a un team

`Team` usa `HasRoles` (polimórfico via `model_roles`):

```php
$team = Team::find('...');
$team->assignRole('editor');
// Ahora todos los members del team heredan permisos de 'editor'
```

## Permisos a entidades para un team

`entity_permissions` es polimórfico — un team puede tener acceso directo a un recurso:

```php
EntityPermission::create([
    'entity_type'     => Folder::class,
    'entity_id'       => 42,
    'grantable_type'  => Team::class,
    'grantable_id'    => $marketingTeam->id,
    'action'          => 'edit',
]);
```

Los members heredan el acceso vía resolución de gates.

## CRUD HTTP opt-in

```php
// routes/api.private.php
Route::middleware(['auth:api', 'tenant.require'])->group(function () {
    \Innertia\Platform\Teams\Routes::register();
});
```

Endpoints generados:
- `GET    /teams`                — lista plana (cliente arma árbol via parent_team_id)
- `POST   /teams`
- `GET    /teams/{id}`           — detail con members + parent + children
- `PUT    /teams/{id}`
- `DELETE /teams/{id}`
- `PUT    /teams/{id}/members`   — sync members: `{ members: [{user_id, role_in_team}] }`

`SyncTeamMembers` preserva el `joined_at` original cuando solo cambia el `role_in_team` — no es un detach+attach naïve.

## Crear un team desde código

```php
use Innertia\Platform\Teams\UseCases\CreateTeam;

$team = (new CreateTeam(
    tenantId:       Innertia::tenant()->getKey(),
    name:           'Comité de Calidad',
    description:    'Responsable del sistema ISO',
    parentTeamId:   null,                              // o id del parent
    organizationId: Innertia::organization()?->current(),  // auto-org si feature on
))->execute();
```

`CreateTeam` captura automáticamente `Innertia::organization()->current()` cuando el feature está activo — el team queda scoped a la org actual sin que pases nada.

Via artisan:

```bash
php artisan innertia:team:create acme "Comité de Calidad" --description="..." --parent={uuid} --org={id}
php artisan innertia:team:list --tenant=acme
```

## Cómo extender Team con columnas propias

Mismo patrón que Organizations:

```php
// 1. Migration de la app
Schema::table('teams', function ($t) {
    $t->string('color')->nullable();
    $t->string('avatar')->nullable();
});

// 2. Modelo extendido
class Team extends \Innertia\Platform\Teams\Models\Team {
    protected $fillable = [...parent::$fillable, 'color', 'avatar'];
}

// 3. config('innertia.teams.model') = App\Models\Team::class

// 4. Controller extendido — hooks
class TeamsController extends \Innertia\Platform\Teams\Http\Controllers\TeamsController {
    protected function extraStoreRules(): array {
        return [
            'color'  => 'nullable|string|max:32',
            'avatar' => 'nullable|string|max:100',
        ];
    }
    protected function extraUpdateRules(): array { return $this->extraStoreRules(); }
    protected function extraFields(Request $r, $team = null): array {
        return array_filter(
            ['color' => $r->input('color'), 'avatar' => $r->input('avatar')],
            fn ($v) => $v !== null,
        );
    }
    protected function showRelations(): array {
        return [...parent::showRelations(), 'members.worker:id,user_id,position'];
    }
}

// 5. Mount con el de la app
\Innertia\Platform\Teams\Routes::register('teams', \App\Http\TeamsController::class);
```

## /auth/me incluye teams

Cuando TeamsFeature está activo, `/auth/me` devuelve:

```json
{
  "teams": [
    { "id": "uuid-...", "name": "Comité de Calidad", "parent_team_id": null, "organization_id": 1, "role_in_team": "lead" }
  ]
}
```

El frontend puede usar esto para renderizar el árbol de pertenencia sin extra requests.

## Eventos emitidos

Teams emite 4 eventos típados:

| Evento | Cuándo dispara | Payload |
|---|---|---|
| `TeamEvent::Created` | `CreateTeam::execute()` | team_id, name, organization_id, parent_team_id |
| `TeamEvent::Updated` | `UpdateTeam::execute()` | team_id, changes (old/new) |
| `TeamEvent::Deleted` | `DeleteTeam::execute()` | team_id, name |
| `TeamEvent::MembersSynced` | `SyncTeamMembers::execute()` | team_id, added (user IDs), removed |

Ejemplo:

```php
Innertia::events()->listen(TeamEvent::MembersSynced, function ($event) {
    foreach ($event->added as $userId) {
        // welcome notification
    }
});
```

## Skills relacionados

- `innertia-organizations` — Teams pueden ser org-scoped
- `innertia-rbac` — permisos, roles, las 8 fuentes (incluyendo team membership)
- `innertia-extending` — patrón template method
