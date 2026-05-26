<?php

namespace Innertia\Tags\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Innertia\Tags\Http\Requests\SyncTagsRequest;
use Innertia\Tags\Http\Resources\TagResource;
use Innertia\Tags\UseCases\AttachTags;
use Innertia\Tags\UseCases\DetachTags;
use Innertia\Tags\UseCases\SyncTags;

class TaggablesController extends Controller
{
    public function index(Request $request, string $type, string $id): AnonymousResourceCollection
    {
        $entity = $this->resolveEntity($type, $id);

        return TagResource::collection($entity->tags);
    }

    public function attach(SyncTagsRequest $request, string $type, string $id): AnonymousResourceCollection
    {
        $entity = $this->resolveEntity($type, $id);
        $this->ensureCanAttach($request, $entity);

        (new AttachTags(entity: $entity, names: $request->input('tags')))->execute();

        return TagResource::collection($entity->fresh()->tags);
    }

    public function detach(Request $request, string $type, string $id, string $tagId): JsonResponse
    {
        $entity = $this->resolveEntity($type, $id);
        $this->ensureCanAttach($request, $entity);

        $entity->tags()->detach($tagId);

        return response()->json(null, 204);
    }

    public function sync(SyncTagsRequest $request, string $type, string $id): AnonymousResourceCollection
    {
        $entity = $this->resolveEntity($type, $id);
        $this->ensureCanAttach($request, $entity);

        (new SyncTags(entity: $entity, names: $request->input('tags')))->execute();

        return TagResource::collection($entity->fresh()->tags);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveEntity(string $type, string $id)
    {
        $map = config('innertia.tags.taggable_types', []);

        if (! isset($map[$type])) {
            abort(404, "Unknown taggable type: {$type}");
        }

        $modelClass = $map[$type];
        $entity     = $modelClass::find($id);

        if (! $entity) {
            abort(404, 'Entity not found.');
        }

        return $entity;
    }

    private function ensureCanAttach(Request $request, $entity): void
    {
        $callback = config('innertia.tags.authorize_attach');
        $user     = $request->user();

        if (is_callable($callback)) {
            if (! $callback($user, $entity)) {
                abort(403, 'Not authorized to tag this entity.');
            }
            return;
        }

        // No callback configured — default to "must be authenticated and pass Laravel policy 'update'".
        if (! $user) {
            abort(403, 'Authentication required to modify tags.');
        }

        if (method_exists($user, 'can') && ! $user->can('update', $entity)) {
            abort(403, 'Not authorized to tag this entity.');
        }
    }
}
