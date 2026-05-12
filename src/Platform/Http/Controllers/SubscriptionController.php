<?php

namespace Innertia\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Models\Subscription;
use Innertia\Platform\UseCases\Subscribe;
use Innertia\Platform\UseCases\Unsubscribe;
use Innertia\Platform\UseCases\UpdateSubscription;

class SubscriptionController extends Controller
{
    /**
     * GET /subscriptions
     *
     * List all subscriptions for the authenticated user,
     * optionally filtered by subscribable type.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Subscription::where('subscriber_id', $request->user()->getAuthIdentifier())
            ->orderBy('created_at', 'desc');

        if ($request->filled('type')) {
            $query->where('subscribable_type', $request->input('type'));
        }

        return response()->json($query->get());
    }

    /**
     * POST /subscriptions
     *
     * Subscribe the authenticated user to a model's events.
     *
     * Body:
     *   subscribable_type  string   — fully qualified model class, e.g. App\Domains\Orders\Models\Order
     *   subscribable_id    string   — model UUID
     *   events             string[] — event keys to watch, e.g. ['order.shipped'] or ['*']
     *   channels           string[] — delivery channels: ['mail', 'realtime']
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscribable_type' => 'required|string',
            'subscribable_id'   => 'required|string',
            'events'            => 'sometimes|array',
            'events.*'          => 'string',
            'channels'          => 'sometimes|array',
            'channels.*'        => 'string|in:mail,realtime',
        ]);

        $subscription = (new Subscribe(
            user:             $request->user(),
            subscribableType: $data['subscribable_type'],
            subscribableId:   $data['subscribable_id'],
            events:           $data['events']   ?? ['*'],
            channels:         $data['channels'] ?? ['mail'],
        ))->execute();

        return response()->json($subscription, 201);
    }

    /**
     * PATCH /subscriptions/{id}
     *
     * Update the events and/or channels of an existing subscription.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'events'   => 'sometimes|array',
            'events.*' => 'string',
            'channels' => 'sometimes|array',
            'channels.*' => 'string|in:mail,realtime',
        ]);

        $subscription = (new UpdateSubscription(
            user:           $request->user(),
            subscriptionId: $id,
            events:         $data['events']   ?? null,
            channels:       $data['channels'] ?? null,
        ))->execute();

        return response()->json($subscription);
    }

    /**
     * DELETE /subscriptions/{id}
     *
     * Unsubscribe (delete the subscription).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        (new Unsubscribe(
            user:           $request->user(),
            subscriptionId: $id,
        ))->execute();

        return response()->json(null, 204);
    }
}
