<?php

namespace Innertia\Files\Directories\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Innertia\DataTable\DataTree;
use Innertia\Files\Directories\DirectoriesFeature;
use Innertia\Files\Directories\Exceptions\CircularMoveException;
use Innertia\Files\Directories\Exceptions\CrossOwnerMoveException;
use Innertia\Files\Directories\Exceptions\DirectoryNotFoundException;
use Innertia\Files\Directories\Exceptions\DuplicateDirectoryNameException;
use Innertia\Files\Directories\Exceptions\InvalidNameException;
use Innertia\Files\Directories\Exceptions\MaxDepthExceededException;
use Innertia\Files\Directories\Exceptions\OrphanedRestoreException;
use Innertia\Files\Directories\Exceptions\ParentTrashedException;
use Innertia\Files\Directories\Exceptions\RestoreCollisionException;
use Innertia\Files\Directories\Http\Resources\DirectoryResource;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Directories\UseCases\CreateDirectory;
use Innertia\Files\Directories\UseCases\EmptyTrash;
use Innertia\Files\Directories\UseCases\HardDeleteDirectory;
use Innertia\Files\Directories\UseCases\RestoreDirectory;

class DirectoriesController extends Controller
{
    // ── Exception codes ───────────────────────────────────────────────────────

    private const CODES_422 = [
        CircularMoveException::class,
        MaxDepthExceededException::class,
        CrossOwnerMoveException::class,
        InvalidNameException::class,
        ParentTrashedException::class,
        OrphanedRestoreException::class,
        RestoreCollisionException::class,
    ];

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        $model = DirectoriesFeature::modelClass();

        $query = $model::query()->whereNull('parent_id')->orderBy('name');

        if ($ownerType = $request->query('owner_type')) {
            $resolvedType = $this->resolveOwnerType($ownerType);
            $query->where('owner_type', $resolvedType);
        }

        if ($ownerId = $request->query('owner_id')) {
            $query->where('owner_id', $ownerId);
        }

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        return DirectoryResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, string $id): DirectoryResource
    {
        $model = DirectoriesFeature::modelClass();
        $dir   = $model::withTrashed()->find($id);

        if (! $dir) {
            abort(404, 'Directory not found.');
        }

        return DirectoryResource::single($dir)->additional([]);
    }

    public function tree(Request $request, string $id): JsonResponse
    {
        $model  = DirectoriesFeature::modelClass();
        $rootDir = $model::find($id);

        if (! $rootDir) {
            abort(404, 'Directory not found.');
        }

        $maxDepth = (int) $request->query('max_depth', DirectoriesFeature::maxDepth());

        return DataTree::create('directories', $model)
            ->columns(['name', 'depth', 'parent_id', 'path'])
            ->parentColumn('parent_id')
            ->maxDepth($maxDepth)
            ->prepareQuery(fn ($q) => $q->where('path', 'like', $rootDir->path . '%'))
            ->render($request);
    }

    public function store(Request $request): JsonResponse
    {
        $rules = array_merge([
            'name'       => ['required', 'string', 'max:255'],
            'parent_id'  => ['nullable', 'string'],
            'owner_type' => ['nullable', 'string'],
            'owner_id'   => ['nullable', 'string'],
        ], $this->extraStoreRules());

        $request->validate($rules);

        $model    = DirectoriesFeature::modelClass();
        $parent   = null;
        $owner    = null;

        if ($parentId = $request->input('parent_id')) {
            $parent = $model::find($parentId);
            if (! $parent) {
                return response()->json(['message' => 'Parent directory not found.'], 404);
            }
        }

        if ($ownerType = $request->input('owner_type')) {
            $resolvedType = $this->resolveOwnerType($ownerType);
            $ownerId      = $request->input('owner_id');

            // Build a minimal "owner" object for CreateDirectory
            $owner = new class($resolvedType, $ownerId) extends \Illuminate\Database\Eloquent\Model {
                public function __construct(private string $ownerType, private ?string $ownerId)
                {
                    parent::__construct([]);
                }

                public function getKey(): mixed { return $this->ownerId; }
            };
            // Use full class name trick: override static::class
        }

        try {
            $dir = (new CreateDirectory(
                parent: $parent,
                name:   $request->input('name'),
                owner:  null, // owner is set via fillable below if needed
            ))->execute();
        } catch (DirectoryNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (DuplicateDirectoryNameException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (InvalidNameException|ParentTrashedException|MaxDepthExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Apply owner if provided
        if ($request->filled('owner_type') && $request->filled('owner_id')) {
            $resolvedType = $this->resolveOwnerType($request->input('owner_type'));
            $dir->owner_type = $resolvedType;
            $dir->owner_id   = $request->input('owner_id');
            $dir->save();
        }

        $extra = $this->extraFields($request, $dir);
        if (! empty($extra)) {
            $dir->forceFill($extra)->save();
        }

        return DirectoryResource::single($dir->fresh())->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $rules = array_merge([
            'name'      => ['sometimes', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'string'],
        ], $this->extraUpdateRules());

        $request->validate($rules);

        $model = DirectoriesFeature::modelClass();
        $dir   = $model::find($id);

        if (! $dir) {
            return response()->json(['message' => 'Directory not found.'], 404);
        }

        try {
            if ($request->has('name') && $request->input('name') !== $dir->name) {
                $dir = $dir->rename($request->input('name'));
            }

            if ($request->has('parent_id')) {
                $parentId = $request->input('parent_id');

                if ($parentId === null) {
                    $dir = $dir->moveToRoot();
                } else {
                    $newParent = $model::find($parentId);
                    if (! $newParent) {
                        return response()->json(['message' => 'Parent directory not found.'], 404);
                    }
                    $dir = $dir->moveTo($newParent);
                }
            }
        } catch (DirectoryNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (DuplicateDirectoryNameException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (CircularMoveException|MaxDepthExceededException|CrossOwnerMoveException|InvalidNameException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $extra = $this->extraFields($request, $dir);
        if (! empty($extra)) {
            $dir->forceFill($extra)->save();
        }

        return DirectoryResource::single($dir->fresh())->response();
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $model = DirectoriesFeature::modelClass();
        $dir   = $model::withTrashed()->find($id);

        if (! $dir) {
            return response()->json(['message' => 'Directory not found.'], 404);
        }

        $force   = $request->boolean('force');
        $cascade = $request->boolean('cascade');

        try {
            if ($force) {
                (new HardDeleteDirectory($dir, $cascade))->execute();
            } else {
                $dir->trash();
            }
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $model = DirectoriesFeature::modelClass();
        $dir   = $model::withTrashed()->find($id);

        if (! $dir) {
            return response()->json(['message' => 'Directory not found.'], 404);
        }

        $relocateParent = null;
        if ($parentId = $request->input('parent_id')) {
            $relocateParent = $model::find($parentId);
            if (! $relocateParent) {
                return response()->json(['message' => 'Relocate parent directory not found.'], 404);
            }
        }

        try {
            $restored = (new RestoreDirectory($dir, $relocateParent))->execute();
        } catch (OrphanedRestoreException|RestoreCollisionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return DirectoryResource::single($restored)->response();
    }

    public function trash(Request $request): AnonymousResourceCollection
    {
        $model = DirectoriesFeature::modelClass();

        $query = $model::onlyTrashed()->orderBy('deleted_at', 'desc');

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        return DirectoryResource::collection($query->paginate($perPage));
    }

    public function emptyTrash(Request $request): JsonResponse
    {
        $count = (new EmptyTrash())->execute();

        return response()->json(['deleted' => $count]);
    }

    // ── Extension hooks (template method) ────────────────────────────────────

    protected function extraStoreRules(): array { return []; }
    protected function extraUpdateRules(): array { return []; }
    protected function extraFields(Request $request, ?Directory $dir = null): array { return []; }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveOwnerType(string $alias): string
    {
        $map = config('innertia.directories.owner_types', []);

        return $map[$alias] ?? $alias;
    }
}
