<?php

namespace Innertia\Billing\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Innertia\Billing\Enums\PaymentMethod;
use Innertia\Billing\Models\Statement;
use Innertia\Billing\Models\Subscription;
use Innertia\Billing\Support\SpaceOverview;
use Innertia\Billing\UseCases\ChangeSubscriptionStatus;
use Innertia\Billing\UseCases\StartSubscription;
use Innertia\Billing\UseCases\UpdateSubscription;
use Innertia\Exceptions\NotFoundException;

/** Platform: subscription of a space (by tenant key). */
class SpacesController extends Controller
{
    public function show(string $key): JsonResponse
    {
        $tenant = $this->tenant($key);
        $statements = Statement::query()->where('tenant_id', (string) $tenant->getKey())->orderByDesc('period_start')->limit(24)->get();

        return response()->json(SpaceOverview::build($tenant) + [
            'statements' => $statements->map(fn ($s) => Present::statement($s))->values(),
        ]);
    }

    public function start(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'plan_id'        => 'required|uuid',
            'payment_method' => ['sometimes', Rule::enum(PaymentMethod::class)],
            'overrides'      => 'nullable|array',
            'starts_at'      => 'nullable|date_format:Y-m-d',
            'trial_days'     => 'nullable|integer|min:0|max:365',
        ]);

        $subscription = (new StartSubscription(
            tenantKey: $key,
            planId: $data['plan_id'],
            paymentMethod: PaymentMethod::from($data['payment_method'] ?? 'invoice'),
            overrides: $data['overrides'] ?? [],
            startsAt: $data['starts_at'] ?? null,
            trialDays: $data['trial_days'] ?? null,
        ))->execute();

        return response()->json(Present::subscription($subscription), 201);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['sometimes', Rule::enum(PaymentMethod::class)],
            'overrides'      => 'sometimes|nullable|array',
            'plan_id'        => 'sometimes|nullable|uuid',
        ]);

        return response()->json(Present::subscription((new UpdateSubscription($this->subscription($key), $data))->execute()));
    }

    /** POST spaces/{key}/subscription/{action}: suspend | reactivate | cancel */
    public function action(string $key, string $action): JsonResponse
    {
        abort_unless(in_array($action, ['suspend', 'reactivate', 'cancel'], true), 404);

        return response()->json(Present::subscription((new ChangeSubscriptionStatus($this->subscription($key), $action))->execute()));
    }

    private function tenant(string $key): object
    {
        $model = config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class;

        return $model::query()->where('key', $key)->first() ?? throw new NotFoundException("Espacio \"{$key}\" no encontrado.");
    }

    private function subscription(string $key): Subscription
    {
        return Subscription::query()->where('tenant_id', (string) $this->tenant($key)->getKey())->first()
            ?? throw new NotFoundException('El espacio no tiene suscripción.');
    }
}
