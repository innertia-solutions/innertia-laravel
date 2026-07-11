<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Auth\Social\ConfigureSocialite;
use Innertia\Auth\Social\SocialLogin;
use Innertia\Auth\Social\SocialProvider;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Redirect the user to the provider's OAuth page.
     *
     * GET /auth/{provider}/redirect?context=backoffice
     *
     * The `context` value is encoded in the OAuth state so it survives
     * the round-trip back to the callback endpoint.
     */
    public function redirect(string $provider, Request $request): JsonResponse|RedirectResponse
    {
        $request->validate(['context' => 'required|string']);

        $p = SocialProvider::from($provider);

        app(ConfigureSocialite::class)->configure($p);

        // stateless: las rutas API no tienen sesión; el `context` viaja en el
        // `state` (el callback también usa ->stateless()). Sin esto, Socialite
        // intenta guardar el state en sesión y falla con "Session store not set".
        $redirect = Socialite::driver($p->driver())
            ->stateless()
            ->with(['state' => $request->input('context')])
            ->redirect();

        // SPA flow: return the URL as JSON so the frontend can redirect
        if ($request->expectsJson()) {
            return response()->json(['url' => $redirect->getTargetUrl()]);
        }

        return $redirect;
    }

    /**
     * Handle the provider callback.
     *
     * GET /auth/{provider}/callback?code=xxx&state=backoffice[:join_token]
     *
     * El `state` puede llevar `context` o `context:join_token`. Flujo SPA (XHR) →
     * JSON {token,user}; flujo browser (el provider redirige aquí directo) → redirige
     * al front con el token en el fragmento (#token=...&context=...).
     */
    public function callback(string $provider, Request $request): JsonResponse|RedirectResponse
    {
        $p   = SocialProvider::from($provider);
        $raw = (string) $request->input('state');
        [$context, $joinToken] = array_pad(explode(':', $raw, 2), 2, null);

        if (! $context) {
            return response()->json(['message' => 'Missing context.'], 422);
        }

        app(ConfigureSocialite::class)->configure($p);

        $socialUser = Socialite::driver($p->driver())->stateless()->user();

        $result = (new SocialLogin(
            provider:   $p,
            socialUser: $socialUser,
            state:      array_filter(['context' => $context, 'join_token' => $joinToken]),
        ))->execute();

        // Flujo browser → redirige al front con el token en el fragmento.
        if (! $request->expectsJson()) {
            $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');
            $frag = http_build_query([
                'token'   => $result['token'],
                'context' => $context,
                'created' => ! empty($result['created']) ? '1' : '0',
            ]);

            return redirect()->away($frontend.'/auth/callback#'.$frag);
        }

        return response()->json($result);
    }
}
