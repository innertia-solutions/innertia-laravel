<?php

namespace Innertia\Devtools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Devtools\Events\TinkerOutputEvent;
use Innertia\Devtools\Tinker\TinkerAuditLog;
use Innertia\Devtools\Tinker\TinkerEvaluator;
use Innertia\Devtools\Tinker\TinkerSandbox;
use Innertia\Devtools\Tinker\TinkerSession;

class TinkerController extends Controller
{
    public function create(): JsonResponse
    {
        if (! config('innertia.devtools.tinker.enabled', false)) {
            return response()->json([
                'message' => 'Tinker not enabled. Set DEVTOOLS_TINKER_ENABLED=true.',
            ], 403);
        }

        $session = TinkerSession::create();

        return response()->json([
            'session_id' => $session->id(),
            'channel'    => $session->channel(),
            'expires_in' => config('innertia.devtools.tinker.session_ttl', 1800),
        ], 201);
    }

    public function eval(Request $request, string $id): JsonResponse
    {
        $session = TinkerSession::find($id);

        if (! $session) {
            return response()->json(['message' => 'Session not found or expired.'], 404);
        }

        $data = $request->validate(['code' => 'required|string']);

        try {
            TinkerSandbox::validate($data['code']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        TinkerAuditLog::record($session->id(), $data['code'], $request->ip());

        $result = (new TinkerEvaluator())->evaluate($session, $data['code']);

        try {
            broadcast(new TinkerOutputEvent($session->id(), $result));
        } catch (\Throwable) {
            // Broadcasting failure must never break the HTTP response
        }

        return response()->json($result);
    }

    public function destroy(string $id): JsonResponse
    {
        TinkerSession::find($id)?->destroy();

        return response()->json(['ok' => true]);
    }
}
