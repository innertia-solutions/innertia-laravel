<?php

namespace Innertia\PublicApi\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Facades\Innertia;
use Innertia\PublicApi\Http\CursorPage;
use Innertia\Webhooks\Models\Webhook;
use Innertia\Webhooks\Models\WebhookLog;
use Innertia\Webhooks\UseCases\CreateWebhook;
use Innertia\Webhooks\UseCases\DeleteWebhook;
use Innertia\Webhooks\UseCases\ResendWebhookDelivery;
use Innertia\Webhooks\UseCases\RotateWebhookSecret;
use Innertia\Webhooks\UseCases\SendTestWebhook;
use Innertia\Webhooks\UseCases\UpdateWebhook;
use Innertia\Webhooks\WebhookEvents;

/**
 * Management of webhook subscriptions for the active space (\Innertia\PublicApi\Routes::manage()).
 */
class WebhooksController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->query()->orderByDesc('id')->get()->map->toApiArray()->values()]);
    }

    public function events(): JsonResponse
    {
        return response()->json(['data' => array_values(array_map(fn ($e) => $e->toArray(), WebhookEvents::all()))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url'             => 'required|string|max:2000',
            'events'          => 'required|array|min:1',
            'events.*'        => 'string|max:120',
            'description'     => 'nullable|string|max:255',
            'organization_id' => 'nullable',
            'scopes'          => 'nullable|array',
            'scopes.*'        => 'string|max:100',
            'active'          => 'nullable|boolean',
        ]);

        $result = (new CreateWebhook(
            url: $data['url'],
            events: $data['events'],
            description: $data['description'] ?? null,
            organizationId: isset($data['organization_id']) ? (string) $data['organization_id'] : null,
            scopes: $data['scopes'] ?? [],
            active: (bool) ($data['active'] ?? true),
        ))->execute();

        return response()->json(['data' => $result['webhook']->toApiArray(), 'secret' => $result['secret']], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->find($id)->toApiArray()]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'url'             => 'sometimes|required|string|max:2000',
            'events'          => 'sometimes|required|array|min:1',
            'events.*'        => 'string|max:120',
            'description'     => 'sometimes|nullable|string|max:255',
            'organization_id' => 'sometimes|nullable',
            'scopes'          => 'sometimes|nullable|array',
            'scopes.*'        => 'string|max:100',
            'active'          => 'sometimes|boolean',
        ]);

        if (array_key_exists('organization_id', $data) && $data['organization_id'] !== null) {
            $data['organization_id'] = (string) $data['organization_id'];
        }
        if (array_key_exists('scopes', $data)) {
            $data['scopes'] ??= [];
        }

        return response()->json(['data' => (new UpdateWebhook($this->find($id), $data))->execute()->toApiArray()]);
    }

    public function destroy(string $id): JsonResponse
    {
        (new DeleteWebhook($this->find($id)))->execute();

        return response()->json(['ok' => true]);
    }

    public function rotateSecret(string $id): JsonResponse
    {
        $result = (new RotateWebhookSecret($this->find($id)))->execute();

        return response()->json(['data' => $result['webhook']->toApiArray(), 'secret' => $result['secret']]);
    }

    public function test(string $id): JsonResponse
    {
        return response()->json(['data' => (new SendTestWebhook($this->find($id)))->execute()->toApiArray()], 202);
    }

    public function logs(Request $request, string $id): JsonResponse
    {
        $webhook = $this->find($id);
        $query = WebhookLog::query()->where('webhook_id', $webhook->id);

        if ($status = $request->query('status')) {
            $query->where('status', (string) $status);
        }

        return CursorPage::respond($request, $query, fn (WebhookLog $log) => $log->toApiArray());
    }

    public function log(string $id, string $log): JsonResponse
    {
        return response()->json(['data' => $this->findLog($id, $log)->toApiArray(true)]);
    }

    public function resend(string $id, string $log): JsonResponse
    {
        return response()->json(['data' => (new ResendWebhookDelivery($this->findLog($id, $log)))->execute()->toApiArray()], 202);
    }

    private function find(string $id): Webhook
    {
        return $this->query()->findOrFail($id);
    }

    private function findLog(string $id, string $log): WebhookLog
    {
        return WebhookLog::query()->where('webhook_id', $this->find($id)->id)->findOrFail($log);
    }

    private function query(): Builder
    {
        $query = Webhook::query();

        if (Innertia::tenancyEnabled()) {
            $query->where('tenant_id', Innertia::tenant()?->getKey());
        }

        return $query;
    }
}
