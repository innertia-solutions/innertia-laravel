<?php

namespace Innertia\Saas\Clients\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Innertia\Saas\Enums\InvoicingMode;
use Innertia\Saas\Enums\TenantEnvironment;
use Innertia\Saas\Models\Client;
use Innertia\Saas\UseCases\CreateClient;
use Innertia\Saas\UseCases\SetTenantEnvironment;
use Innertia\Saas\UseCases\UpdateClient;

class ClientsController extends Controller
{
    /** Tree.Standard format: client nodes with their spaces inline as children. */
    public function tree(Request $request): JsonResponse
    {
        $search = mb_strtolower(trim((string) $request->input('search', '')));
        $like = fn (Builder $q, string $col) => $q->orWhereRaw("LOWER({$col}) LIKE ?", ["%{$search}%"]);

        $clients = Client::query()
            ->with(['tenants' => fn ($q) => $q->orderBy('name')])
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $w) use ($like) {
                $like($w, 'name');
                $like($w, 'legal_name');
                $like($w, 'tax_id');
                $w->orWhereHas('tenants', function (Builder $t) use ($like) {
                    $t->where(fn (Builder $tw) => $like($like($tw, 'name'), 'key'));
                });
            }))
            ->orderBy('name')
            ->get();

        $data = $clients->map(fn (Client $c) => [
            'id'           => 'client:' . $c->id,
            'type'         => 'client',
            'client_id'    => $c->id,
            'name'         => $c->name,
            'tax_id'       => $c->tax_id,
            'status'       => $c->status,
            'environment'  => null,
            'spaces_count' => $c->tenants->count(),
            'has_children' => $c->tenants->isNotEmpty(),
            'children'     => $c->tenants->map(fn ($t) => [
                'id'           => 'space:' . $t->key,
                'type'         => 'space',
                'client_id'    => $c->id,
                'key'          => $t->key,
                'name'         => $t->name,
                'status'       => $t->status,
                'environment'  => $t->environment?->value,
                'has_children' => false,
                'children'     => [],
            ])->values(),
        ])->values();

        return response()->json([
            'data' => $data,
            'meta' => ['total_nodes' => $clients->count() + $clients->sum(fn ($c) => $c->tenants->count())],
        ]);
    }

    public function show(string $client): JsonResponse
    {
        $model = Client::with(['tenants' => fn ($q) => $q->orderBy('name')])->findOrFail($client);

        return response()->json([
            'client' => $model->toArray(),
            'spaces' => $model->tenants->map(fn ($t) => [
                'key'         => $t->key,
                'name'        => $t->name,
                'status'      => $t->status,
                'environment' => $t->environment?->value,
                'created_at'  => $t->created_at,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'key'  => ['nullable', 'string', 'alpha_dash', 'max:50', Rule::unique('clients', 'key')],
            ...$this->rules(),
        ]);

        $client = (new CreateClient(name: $data['name'], key: $data['key'] ?? null, attributes: $data))->execute();

        return response()->json($client->toArray(), 201);
    }

    public function update(Request $request, string $client): JsonResponse
    {
        $model = Client::findOrFail($client);
        $data = $request->validate([
            'name'   => 'sometimes|string|max:120',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            ...$this->rules(),
        ]);

        return response()->json((new UpdateClient($model, $data))->execute()->toArray());
    }

    public function setEnvironment(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['environment' => ['required', Rule::enum(TenantEnvironment::class)]]);

        $tenant = (new SetTenantEnvironment($key, TenantEnvironment::from($data['environment'])))->execute();

        return response()->json(['key' => $tenant->key, 'environment' => $tenant->environment->value]);
    }

    /** Shared optional fields for store and update. */
    private function rules(): array
    {
        return [
            'legal_name'         => 'nullable|string|max:200',
            'tax_id'             => 'nullable|string|max:20',
            'business_activity'  => 'nullable|string|max:200',
            'address'            => 'nullable|string|max:250',
            'billing_email'      => 'nullable|email|max:150',
            'invoicing_mode'     => ['nullable', Rule::enum(InvoicingMode::class)],
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'grace_days'         => 'nullable|integer|min:0|max:365',
            'late_fee_percent'   => 'nullable|numeric|min:0|max:100',
            'notes'              => 'nullable|string|max:2000',
        ];
    }
}
