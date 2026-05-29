<?php
declare(strict_types=1);
namespace Innertia\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Api\Events\OrganizationReactivated;
use Innertia\Api\Events\OrganizationSuspended;
use Innertia\Api\Models\Organization;
use Innertia\Api\UseCases\CreateChildOrganization;
use Innertia\Api\UseCases\RegisterOrganization;

class OrganizationsController extends Controller
{
    /** GET /olimpo/organizations */
    public function index(Request $request): JsonResponse
    {
        $orgs = Organization::query()
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('roots_only'), fn ($q) => $q->roots())
            ->with('children')
            ->paginate(25);

        return response()->json($orgs);
    }

    /** POST /olimpo/organizations */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'key'  => 'required|string|max:100|unique:organizations,key|alpha_dash',
        ]);

        $result = (new RegisterOrganization(
            name: $data['name'],
            key:  $data['key'],
        ))->execute();

        return response()->json([
            'organization' => $result['organization'],
            'raw_key'      => $result['raw_key'],
            'api_key'      => $result['api_key'],
        ], 201);
    }

    /** POST /olimpo/organizations/{organization}/children */
    public function storeChild(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'key'  => 'required|string|max:100|unique:organizations,key|alpha_dash',
        ]);

        $result = (new CreateChildOrganization(
            parent: $organization,
            name:   $data['name'],
            key:    $data['key'],
        ))->execute();

        return response()->json([
            'organization' => $result['organization'],
            'raw_key'      => $result['raw_key'],
            'api_key'      => $result['api_key'],
        ], 201);
    }

    /** GET /olimpo/organizations/{organization} */
    public function show(Organization $organization): JsonResponse
    {
        return response()->json(
            $organization->load(['children', 'apiKeys' => fn ($q) => $q->active()])
        );
    }

    /** PATCH /olimpo/organizations/{organization}/suspend */
    public function suspend(Organization $organization): JsonResponse
    {
        $organization->suspend();
        OrganizationSuspended::dispatch($organization);
        return response()->json(['status' => 'suspended']);
    }

    /** PATCH /olimpo/organizations/{organization}/reactivate */
    public function reactivate(Organization $organization): JsonResponse
    {
        $organization->reactivate();
        OrganizationReactivated::dispatch($organization);
        return response()->json(['status' => 'active']);
    }

    /** DELETE /olimpo/organizations/{organization} */
    public function destroy(Organization $organization): JsonResponse
    {
        $organization->delete();
        return response()->json(null, 204);
    }
}
