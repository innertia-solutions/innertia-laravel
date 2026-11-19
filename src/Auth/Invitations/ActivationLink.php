<?php

namespace Innertia\Auth\Invitations;

/**
 * Front URL where the invited user creates their password:
 *   {app.frontend_url|app.url}{innertia.auth.frontend_activation_path}?token=…&email=…
 * Default path '/backoffice/auth/set-password' (the page posts to auth/password/reset, which
 * accepts invitation tokens too).
 */
final class ActivationLink
{
    public static function url(string $email, string $token): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $path = '/' . ltrim((string) config('innertia.auth.frontend_activation_path', '/backoffice/auth/set-password'), '/');

        return $base . $path . '?token=' . $token . '&email=' . urlencode($email);
    }
}
