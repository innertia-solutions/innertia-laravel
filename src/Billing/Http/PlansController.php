<?php

namespace Innertia\Billing\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Innertia\Billing\Enums\BillingInterval;
use Innertia\Billing\Meters;
use Innertia\Billing\Models\Plan;
use Innertia\Billing\UseCases\SavePlan;

class PlansController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Plan::query()->with('meters')->orderBy('base_price')->get()->map(fn ($p) => Present::plan($p))->values()]);
    }

    public function meters(): JsonResponse
    {
        return response()->json(['data' => array_values(array_map(fn ($m) => $m->toArray(), Meters::active()))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(null));

        return response()->json(Present::plan((new SavePlan($data))->execute()), 201);
    }

    public function update(Request $request, string $plan): JsonResponse
    {
        $model = Plan::findOrFail($plan);
        $data = $request->validate($this->rules($model));

        return response()->json(Present::plan((new SavePlan($data, $model))->execute()));
    }

    private function rules(?Plan $plan): array
    {
        $req = $plan ? 'sometimes' : 'required';

        return [
            'key'                        => [$req, 'string', 'alpha_dash', 'max:60', Rule::unique('billing_plans', 'key')->ignore($plan?->id)],
            'name'                       => [$req, 'string', 'max:120'],
            'description'                => 'nullable|string|max:250',
            'product'                    => 'nullable|string|max:60',
            'currency'                   => 'sometimes|string|size:3',
            'interval'                   => ['sometimes', Rule::enum(BillingInterval::class)],
            'base_price'                 => 'sometimes|integer|min:0',
            'included_seats'             => 'sometimes|integer|min:0',
            'seat_price'                 => 'sometimes|integer|min:0',
            'trial_days'                 => 'sometimes|integer|min:0|max:365',
            'tax_rate'                   => 'sometimes|numeric|min:0|max:100',
            'limits'                     => 'nullable|array',
            'active'                     => 'sometimes|boolean',
            'meters'                     => 'sometimes|array',
            'meters.*.meter'             => ['required', 'distinct', Rule::in(array_keys(Meters::active()))],
            'meters.*.included_quantity' => 'nullable|numeric|min:0',
            'meters.*.unit_price'        => 'nullable|integer|min:0',
            'meters.*.markup_percent'    => 'nullable|numeric|min:0|max:1000',
        ];
    }
}
