<?php

namespace Innertia\Notifications\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Innertia\Exceptions\ForbiddenException;

/**
 * Auth de canales privados de Pusher/Soketi con guard JWT.
 * Solo autoriza el canal propio del usuario: `private-user.{ownId}`.
 * Firma el string de auth de Pusher manualmente (HMAC-SHA256 con el app secret).
 */
class BroadcastAuthController
{
    public function authenticate(Request $request): JsonResponse
    {
        $user     = auth('jwt')->user();
        $socketId = (string) $request->input('socket_id');
        $channel  = (string) $request->input('channel_name');

        if ($channel !== 'private-user.' . $user->getAuthIdentifier()) {
            throw new ForbiddenException('Canal no autorizado.');
        }

        $key    = config('broadcasting.connections.pusher.key');
        $secret = config('broadcasting.connections.pusher.secret');
        $sig    = hash_hmac('sha256', $socketId . ':' . $channel, $secret);

        return response()->json(['auth' => $key . ':' . $sig]);
    }
}
