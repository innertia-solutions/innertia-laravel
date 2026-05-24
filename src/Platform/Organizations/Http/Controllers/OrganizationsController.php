<?php

namespace Innertia\Platform\Organizations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Innertia\Facades\DataTable;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\Models\Organization;
use Innertia\Platform\Organizations\UseCases\CreateOrganization;
use Innertia\Platform\Organizations\UseCases\DeleteOrganization;
use Innertia\Platform\Organizations\UseCases\UpdateOrganization;

/**
 * Default CRUD controller para Organizations.
 *
 * Mountable via Routes::register(). Las apps pueden extender esta clase y
 * pasar su versión al helper para sobrescribir métodos puntuales:
 *
 *   Routes::register('organizations', \App\Http\OrganizationsController::class);
 *
 * Para customización profunda (validación, side-effects), conviene reemplazar
 * los UseCases via container binding:
 *
 *   $this->app->bind(
 *       \Innertia\Platform\Organizations\UseCases\CreateOrganization::class,
 *       \App\UseCases\MyCreateOrganization::class
 *   );
 *
 * y override el método del controller (o forkear el controller entero).
 */
class OrganizationsController
{
    protected function model(): string
    {
        return config('innertia.organizations.model', Organization::class);
    }

    /* ── Hooks de extensión ─────────────────────────────────────────────────
     |
     | Override estos métodos en una subclase de la app para agregar campos
     | propios sin tocar el código del paquete:
     |
     |   class App\Http\OrganizationsController extends \Innertia\...\OrganizationsController {
     |       protected function extraStoreRules(): array {
     |           return ['owner_id' => 'required|uuid|exists:users,id'];
     |       }
     |       protected function extraFields(Request $r, ?Model $org = null): array {
     |           return ['owner_id' => $r->input('owner_id')];
     |       }
     |   }
     |
     | Luego: Routes::register('organizations', App\Http\OrganizationsController::class).
     */

    /** Reglas de validación extra para store(). */
    protected function extraStoreRules(): array { return []; }

    /** Reglas de validación extra para update(). */
    protected function extraUpdateRules(): array { return []; }

    /**
     * Campos extra a persistir. $org viene null en store(), populated en update().
     * @return array<string, mixed>
     */
    protected function extraFields(Request $request, $org = null): array { return []; }

    /** Columnas extras a incluir en el DataTable del index. */
    protected function indexColumns(): array
    {
        return ['id', 'name', 'key', 'active', 'created_at'];
    }

    public function index(Request $request): mixed
    {
        return DataTable::create('organizations')
            ->columns($this->indexColumns())
            ->render($this->model()::query(), $request);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = Innertia::tenant()?->getKey();

        $data = $request->validate(array_merge([
            'name'   => 'required|string|max:255',
            'key'    => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('organizations', 'key')->where('tenant_id', $tenantId),
            ],
            'active' => 'sometimes|boolean',
        ], $this->extraStoreRules()));

        $org = (new CreateOrganization(
            tenantId: $tenantId,
            name:     $data['name'],
            key:      $data['key'],
            active:   $data['active'] ?? true,
            extra:    $this->extraFields($request),
        ))->execute();

        return response()->json($org, 201);
    }

    public function show(int|string $id): JsonResponse
    {
        return response()->json($this->model()::findOrFail($id));
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        $tenantId = Innertia::tenant()?->getKey();
        $org      = $this->model()::findOrFail($id);

        $data = $request->validate(array_merge([
            'name'   => 'sometimes|string|max:255',
            'key'    => [
                'sometimes', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('organizations', 'key')
                    ->where('tenant_id', $tenantId)
                    ->ignore($id),
            ],
            'active' => 'sometimes|boolean',
        ], $this->extraUpdateRules()));

        $org = (new UpdateOrganization(
            id:     $id,
            name:   $data['name']   ?? null,
            key:    $data['key']    ?? null,
            active: $data['active'] ?? null,
            extra:  $this->extraFields($request, $org),
        ))->execute();

        return response()->json($org);
    }

    public function destroy(int|string $id): JsonResponse
    {
        (new DeleteOrganization(id: $id))->execute();
        return response()->json(null, 204);
    }
}
