<?php

namespace Innertia\Files\Directories\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Files\Directories\DirectoriesFeature;
use Innertia\Files\Directories\UseCases\RevokeDirectoryShare;
use Innertia\Files\Directories\UseCases\ShareDirectory;
use Innertia\Files\Http\Resources\EntityPermissionResource;

class DirectoryGrantsController extends Controller
{
    public function index(Request $request, string $id): JsonResponse
    {
        $model = DirectoriesFeature::modelClass();
        $dir   = $model::find($id);

        if (! $dir) {
            return response()->json(['message' => 'Directory not found.'], 404);
        }

        $grants = EntityPermission::where('entity_type', $model)
            ->where('entity_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return EntityPermissionResource::collection($grants)->response();
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'grantable_type'  => ['required', 'string', 'in:user,role'],
            'grantable_id'    => ['required', 'string'],
            'grantable_class' => ['required', 'string'],
            'action'          => ['required', 'string', 'in:access,view,edit,manage'],
        ]);

        $model = DirectoriesFeature::modelClass();
        $dir   = $model::find($id);

        if (! $dir) {
            return response()->json(['message' => 'Directory not found.'], 404);
        }

        $grantable = $this->resolveGrantable(
            $request->input('grantable_class'),
            $request->input('grantable_id')
        );

        if (! $grantable) {
            return response()->json(['message' => 'Grantable not found.'], 404);
        }

        $grant = (new ShareDirectory($dir, $grantable, $request->input('action')))->execute();

        return (new EntityPermissionResource($grant))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'grantable_class' => ['required', 'string'],
            'grantable_id'    => ['required', 'string'],
            'action'          => ['required', 'string', 'in:access,view,edit,manage'],
        ]);

        $model = DirectoriesFeature::modelClass();
        $dir   = $model::find($id);

        if (! $dir) {
            return response()->json(['message' => 'Directory not found.'], 404);
        }

        $grantable = $this->resolveGrantable(
            $request->input('grantable_class'),
            $request->input('grantable_id')
        );

        if (! $grantable) {
            return response()->json(['message' => 'Grantable not found.'], 404);
        }

        (new RevokeDirectoryShare($dir, $grantable, $request->input('action')))->execute();

        return response()->json(null, 204);
    }

    /**
     * Resolve a grantable model from its fully-qualified class name + ID.
     *
     * Builds a minimal Model instance with the ID set. We do NOT query the DB
     * because the grant just needs the class name and ID to create an EntityPermission —
     * the grantable doesn't need to exist in any particular table in this system.
     */
    private function resolveGrantable(string $class, string $id): ?Model
    {
        if (! class_exists($class)) {
            return null;
        }

        try {
            $instance = new $class;
            $instance->{$instance->getKeyName()} = $id;
            return $instance;
        } catch (\Throwable) {
            return null;
        }
    }
}
