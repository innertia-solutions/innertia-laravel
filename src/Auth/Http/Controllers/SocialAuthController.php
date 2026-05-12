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
     * GET /auth/{provider}/redirect?app=backoffice
     *
     * The `app` value is encoded in the OAuth state so it survives
     * the round-trip back to the callback endpoint.
     */
    public function redirect(string $provider, Request $request): JsonResponse|RedirectResponse
    {
        $request->validate(['app' => 'required|string']);

        $p = SocialProvider::from($provider);

        app(ConfigureSocialite::class)->configure($p);

        $redirect = Socialite::driver($p->driver())
            ->with(['state' => $request->input('app')])
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
     * GET /auth/{provider}/callback?code=xxx&state=backoffice
     */
    public function callback(string $provider, Request $request): JsonResponse
    {
        $p   = SocialProvider::from($provider);
        $app = $request->input('state');

        if (! $app) {
            return response()->json(['message' => 'Missing app context.'], 422);
        }

        app(ConfigureSocialite::class)->configure($p);

        $socialUser = Socialite::driver($p->driver())->stateless()->user();

        $result = (new SocialLogin(
            provider:   $p,
            socialUser: $socialUser,
            app:        $app,
        ))->execute();

        return response()->json($result);
    }
}
