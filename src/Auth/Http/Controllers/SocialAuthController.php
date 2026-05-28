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

        $redirect = Socialite::driver($p->driver())
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
     * GET /auth/{provider}/callback?code=xxx&state=backoffice
     */
    public function callback(string $provider, Request $request): JsonResponse
    {
        $p       = SocialProvider::from($provider);
        $context = $request->input('state');

        if (! $context) {
            return response()->json(['message' => 'Missing context.'], 422);
        }

        app(ConfigureSocialite::class)->configure($p);

        $socialUser = Socialite::driver($p->driver())->stateless()->user();

        $result = (new SocialLogin(
            provider:   $p,
            socialUser: $socialUser,
            context:    $context,
        ))->execute();

        return response()->json($result);
    }
}
