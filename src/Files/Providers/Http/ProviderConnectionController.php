<?php

namespace Innertia\Files\Providers\Http;

use Illuminate\Http\Request;
use Innertia\Files\Providers\ProviderRegistry;

class ProviderConnectionController
{
    public function __construct(private ProviderRegistry $registry) {}

    public function connect(Request $request, string $provider)
    {
        return redirect()->away(
            $this->registry->for($provider)->connectRedirectUrl($request->query('scope', 'user'), $request->query('tenant'))
        );
    }

    public function callback(Request $request, string $provider)
    {
        $state = json_decode(base64_decode((string) $request->query('state', '')), true) ?: [];
        $this->registry->for($provider)->handleCallback(
            $request->query(), $state['scope'] ?? 'user', $state['tenant'] ?? null
        );

        return response('<!doctype html><script>window.close()</script>Conectado. Podés cerrar esta ventana.', 200)
            ->header('Content-Type', 'text/html');
    }

    public function status(Request $request, string $provider)
    {
        $conn = $this->registry->for($provider)->connectionFor('user', $request->user(), $request->query('tenant'));

        return response()->json(['connected' => (bool) $conn, 'email' => $conn?->email]);
    }

    public function disconnect(Request $request, string $provider)
    {
        $p = $this->registry->for($provider);
        // scope user: resolver por auth()->user(); en tests sin auth se puede pasar ?user_id
        $conn = $p->connectionFor($request->query('scope', 'user'), $request->user(), $request->query('tenant'));
        if (! $conn && $request->query('user_id')) {
            $conn = \Innertia\Files\Providers\ProviderConnection::where('provider', $provider)
                ->where('scope', $request->query('scope', 'user'))
                ->where('user_id', $request->query('user_id'))->first();
        }
        if ($conn) {
            $p->disconnect($conn);
        }

        return response()->json(['connected' => false]);
    }
}
