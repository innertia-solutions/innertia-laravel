<?php

namespace Innertia\Tags\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Innertia\Tags\Exceptions\DuplicateTagException;
use Innertia\Tags\Exceptions\TagNotFoundException;
use Innertia\Tags\Http\Resources\TagResource;
use Innertia\Tags\Models\Tag;
use Innertia\Tags\TagsFeature;
use Innertia\Tags\UseCases\CreateTag;
use Innertia\Tags\UseCases\DeleteTag;
use Innertia\Tags\UseCases\UpdateTag;

class TagsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $model = TagsFeature::modelClass();

        $query = $model::query()->orderBy('name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($ids = $request->query('ids')) {
            $query->whereIn('id', explode(',', $ids));
        }

        $perPage = (int) $request->query('per_page', 25);

        return TagResource::collection($query->paginate($perPage));
    }

    public function popular(Request $request): AnonymousResourceCollection
    {
        $model = TagsFeature::modelClass();
        $limit = (int) $request->query('limit', 20);
        $type  = $request->query('taggable_type');

        $query = $model::query()->popular($limit, $type);

        return TagResource::collection($query->get());
    }

    public function show(string $id): TagResource
    {
        $tag = TagsFeature::modelClass()::find($id);

        if (! $tag) {
            abort(404, 'Tag not found.');
        }

        return new TagResource($tag);
    }

    public function store(Request $request): JsonResponse
    {
        $rules = array_merge([
            'name'  => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], $this->extraStoreRules());

        $request->validate($rules);

        try {
            $tag = (new CreateTag(
                name: $request->input('name'),
                color: $request->input('color'),
            ))->execute();
        } catch (DuplicateTagException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $extra = $this->extraFields($request, $tag);
        if (! empty($extra)) {
            $tag->forceFill($extra)->save();
        }

        return (new TagResource($tag))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id): TagResource
    {
        $rules = array_merge([
            'name'  => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], $this->extraUpdateRules());

        $request->validate($rules);

        try {
            $tag = (new UpdateTag(
                tagId: $id,
                name:  $request->input('name'),
                color: $request->has('color') ? $request->input('color') : null,
            ))->execute();
        } catch (TagNotFoundException $e) {
            abort(404, $e->getMessage());
        } catch (DuplicateTagException $e) {
            abort(409, $e->getMessage());
        }

        $extra = $this->extraFields($request, $tag);
        if (! empty($extra)) {
            $tag->forceFill($extra)->save();
        }

        return new TagResource($tag);
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            (new DeleteTag(tagId: $id))->execute();
        } catch (TagNotFoundException $e) {
            abort(404, $e->getMessage());
        }

        return response()->json(null, 204);
    }

    // ── Extension hooks (template method) ─────────────────────────────────────

    protected function extraStoreRules(): array { return []; }
    protected function extraUpdateRules(): array { return []; }
    protected function extraFields(Request $request, ?Tag $tag = null): array { return []; }
}
