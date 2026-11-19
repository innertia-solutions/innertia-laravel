<?php

namespace Innertia\Auth\Passwords;

use Illuminate\Auth\Notifications\ResetPassword;
use Innertia\Auth\Mailables\ResetPasswordMail;

/**
 * Branded password reset (opt-in, one line in the product provider boot()):
 *
 *   \Innertia\Auth\Passwords\ResetPasswordLinks::register();
 *
 * - Link: {app.frontend_url|app.url}{innertia.auth.frontend_reset_path}?token=…&email=…
 *   (default path '/backoffice/auth/reset-password').
 * - Email: ResetPasswordMail (platform brand) instead of Laravel's generic template, with the
 *   broker expiry (60 min by default).
 */
final class ResetPasswordLinks
{
    public static function register(?string $path = null): void
    {
        ResetPassword::createUrlUsing(fn ($notifiable, string $token) => self::url(
            (string) $notifiable->getEmailForPasswordReset(), $token, $path,
        ));

        ResetPassword::toMailUsing(function ($notifiable, string $token) use ($path) {
            $broker = config('auth.defaults.passwords', 'users');

            return (new ResetPasswordMail(
                name: (string) ($notifiable->name ?? ''),
                resetUrl: self::url((string) $notifiable->getEmailForPasswordReset(), $token, $path),
                expiresInMinutes: (int) config("auth.passwords.{$broker}.expire", 60),
            ))->to($notifiable->getEmailForPasswordReset());
        });
    }

    public static function url(string $email, string $token, ?string $path = null): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $path ??= (string) config('innertia.auth.frontend_reset_path', '/backoffice/auth/reset-password');

        return $base . '/' . ltrim($path, '/') . '?token=' . $token . '&email=' . urlencode($email);
    }
}
