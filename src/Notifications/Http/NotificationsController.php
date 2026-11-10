<?php

namespace Innertia\Notifications\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Notifications\Models\Notification;

class NotificationsController extends Controller
{
    /**
     * GET /notifications
     * GET /notifications?all=1   — include read notifications
     * GET /notifications?page=2  — paginated
     * GET /notifications?per_page=N — page size (1..100, default 15)
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->getAuthIdentifier();

        $query = Notification::where('user_id', $userId)
            ->orderByDesc('created_at');

        if (! $request->boolean('all')) {
            $query->whereNull('read_at');
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 15)));

        $notifications = $query->paginate($perPage);

        return response()->json([
            'data'        => $notifications->items(),
            'total'       => $notifications->total(),
            'unread'      => Notification::where('user_id', $userId)->whereNull('read_at')->count(),
            'current_page'=> $notifications->currentPage(),
            'last_page'   => $notifications->lastPage(),
            'per_page'    => $notifications->perPage(),
        ]);
    }

    /**
     * PATCH /notifications/{id}/read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = Notification::where('user_id', $request->user()->getAuthIdentifier())
            ->findOrFail($id);

        $notification->markRead();

        return response()->json(['read_at' => $notification->read_at]);
    }

    /**
     * PATCH /notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = Notification::where('user_id', $request->user()->getAuthIdentifier())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['marked' => $count]);
    }

    /**
     * DELETE /notifications/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        Notification::where('user_id', $request->user()->getAuthIdentifier())
            ->findOrFail($id)
            ->delete();

        return response()->json(null, 204);
    }

    /**
     * DELETE /notifications
     * Deletes all read notifications for the authenticated user.
     */
    public function destroyRead(Request $request): JsonResponse
    {
        $count = Notification::where('user_id', $request->user()->getAuthIdentifier())
            ->whereNotNull('read_at')
            ->delete();

        return response()->json(['deleted' => $count]);
    }
}
