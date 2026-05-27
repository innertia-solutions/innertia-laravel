<?php

namespace Innertia\Files\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Files\Http\Resources\EntityPermissionResource;
use Innertia\Files\Models\File;
use Innertia\Files\UseCases\RevokeFileShare;
use Innertia\Files\UseCases\ShareFile;

class FileGrantsController extends Controller
{
    public function index(Request $request, string $id): JsonResponse
    {
        $file = File::withTrashed()->find($id);

        if (! $file) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $grants = EntityPermission::where('entity_type', File::class)
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

        $file = File::find($id);

        if (! $file) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $grantable = $this->resolveGrantable(
            $request->input('grantable_class'),
            $request->input('grantable_id')
        );

        if (! $grantable) {
            return response()->json(['message' => 'Grantable not found.'], 404);
        }

        $grant = (new ShareFile($file, $grantable, $request->input('action')))->execute();

        return (new EntityPermissionResource($grant))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'grantable_class' => ['required', 'string'],
            'grantable_id'    => ['required', 'string'],
            'action'          => ['required', 'string', 'in:access,view,edit,manage'],
        ]);

        $file = File::find($id);

        if (! $file) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $grantable = $this->resolveGrantable(
            $request->input('grantable_class'),
            $request->input('grantable_id')
        );

        if (! $grantable) {
            return response()->json(['message' => 'Grantable not found.'], 404);
        }

        (new RevokeFileShare($file, $grantable, $request->input('action')))->execute();

        return response()->json(null, 204);
    }

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
