<?php

namespace Innertia\Ai\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Ai\Models\AiModel;
use Innertia\Ai\Models\AiProvider;
use Innertia\Ai\UseCases\UpdateModelPrices;

/** Model prices (USD) of the platform AI console. */
class AiPricingController extends Controller
{
    public function index(): JsonResponse
    {
        $providers = AiProvider::query()->get(['id', 'key', 'name'])->keyBy('id');

        $models = AiModel::query()->orderBy('name')->get()->map(fn (AiModel $m) => [
            'id'            => $m->id,
            'key'           => $m->key,
            'name'          => $m->name,
            'active'        => (bool) $m->active,
            'capabilities'  => $m->capabilities ?? [],
            'provider'      => ($p = $providers->get($m->provider_id)) ? ['id' => $p->id, 'key' => $p->key, 'name' => $p->name] : null,
            'prices'        => $m->prices(),
            'priced'        => $m->isPriced(),
        ])->values();

        return response()->json(['data' => $models, 'currency' => 'USD']);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $rules = [];
        foreach (AiModel::PRICE_FIELDS as $field) {
            $rules[$field] = 'present|nullable|numeric|min:0|max:100000';
        }
        $data = $request->validate($rules);

        $model = (new UpdateModelPrices(AiModel::findOrFail($id), $data))->execute();

        return response()->json(['id' => $model->id, 'prices' => $model->prices(), 'priced' => $model->isPriced()]);
    }
}
