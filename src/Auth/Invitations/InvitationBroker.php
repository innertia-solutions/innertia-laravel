<?php

namespace Innertia\Auth\Invitations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Own broker for account invitations (separate from password resets, which stay at 60 min):
 * table `invitation_tokens`, TTL config('innertia.auth.invitation_ttl_days', 7) days.
 * Creating a token deletes the previous one → resending invalidates the old link.
 */
final class InvitationBroker
{
    public const VALID = 'valid';
    public const EXPIRED = 'expired';
    public const INVALID = 'invalid';

    private const TABLE = 'invitation_tokens';

    public static function ttlDays(): int
    {
        return max(1, (int) config('innertia.auth.invitation_ttl_days', 7));
    }

    public static function supported(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /** New plain token for $user (the hash is stored). */
    public static function create(object $user): string
    {
        $token = Str::random(64);
        $email = strtolower((string) $user->email);
        $userId = (string) $user->getKey();

        DB::table(self::TABLE)->where('email', $email)->orWhere('user_id', $userId)->delete();
        DB::table(self::TABLE)->insert([
            'email'      => $email,
            'user_id'    => $userId,
            'token'      => self::hash($token),
            'created_at' => now(),
            'expires_at' => now()->addDays(self::ttlDays()),
        ]);

        return $token;
    }

    public static function check(string $email, string $token): string
    {
        if (! self::supported()) {
            return self::INVALID;
        }

        $row = DB::table(self::TABLE)->where('email', strtolower($email))->first();

        if (! $row || ! hash_equals((string) $row->token, self::hash($token))) {
            return self::INVALID;
        }

        return Carbon::parse($row->expires_at)->isPast() ? self::EXPIRED : self::VALID;
    }

    public static function userIdFor(string $email): ?string
    {
        $row = DB::table(self::TABLE)->where('email', strtolower($email))->first();

        return $row ? (string) $row->user_id : null;
    }

    public static function consume(string $email): void
    {
        DB::table(self::TABLE)->where('email', strtolower($email))->delete();
    }

    /**
     * Pending invitation of a user, or null.
     *
     * @return array{invited_at: string, invitation_expires_at: string, invitation_expired: bool}|null
     */
    public static function status(object|string $user): ?array
    {
        if (! self::supported()) {
            return null;
        }

        $userId = is_object($user) ? (string) $user->getKey() : $user;
        $row = DB::table(self::TABLE)->where('user_id', $userId)->first();

        return $row ? self::format($row) : null;
    }

    private static function format(object $row): array
    {
        $expires = Carbon::parse($row->expires_at);

        return [
            'invited_at'            => Carbon::parse($row->created_at)->toIso8601String(),
            'invitation_expires_at' => $expires->toIso8601String(),
            'invitation_expired'    => $expires->isPast(),
        ];
    }

    private static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
