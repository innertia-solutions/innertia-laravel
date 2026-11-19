<?php

namespace Innertia\Webhooks\Signing;

/**
 * Standard Webhooks signatures (https://www.standardwebhooks.com):
 *
 *   webhook-id:        msg_…
 *   webhook-timestamp: unix seconds
 *   webhook-signature: v1,<base64(HMAC-SHA256(key, "{id}.{timestamp}.{body}"))>   (space-separated when several)
 *
 * Secrets are `whsec_<base64 key>`; the HMAC key is the base64-decoded part. A secret without the prefix
 * (legacy plaintext secrets) is used as raw bytes.
 */
final class StandardWebhookSigner
{
    public const PREFIX = 'whsec_';

    /** New random secret: `whsec_` + base64 of 24 random bytes. */
    public static function generateSecret(): string
    {
        return self::PREFIX . base64_encode(random_bytes(24));
    }

    /** `v1,<base64>` for one secret. */
    public static function sign(string $secret, string $messageId, int $timestamp, string $body): string
    {
        $mac = hash_hmac('sha256', "{$messageId}.{$timestamp}.{$body}", self::key($secret), true);

        return 'v1,' . base64_encode($mac);
    }

    /** Header value signing with every given secret (rotation: current + previous). */
    public static function header(array $secrets, string $messageId, int $timestamp, string $body): string
    {
        return implode(' ', array_map(fn (string $s) => self::sign($s, $messageId, $timestamp, $body), $secrets));
    }

    /**
     * Receiver-side verification (also used by tests): any `v1,` signature in the header matching, and a
     * timestamp within the tolerance (seconds).
     */
    public static function verify(string $secret, string $messageId, int $timestamp, string $body, string $signatureHeader, int $tolerance = 300, ?int $now = null): bool
    {
        $now ??= time();

        if (abs($now - $timestamp) > $tolerance) {
            return false;
        }

        $expected = self::sign($secret, $messageId, $timestamp, $body);

        foreach (preg_split('/\s+/', trim($signatureHeader)) ?: [] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** Short display hint: `whsec_…Ab3d`. */
    public static function hint(string $secret): string
    {
        return self::PREFIX . '…' . substr($secret, -4);
    }

    private static function key(string $secret): string
    {
        if (str_starts_with($secret, self::PREFIX)) {
            $decoded = base64_decode(substr($secret, strlen(self::PREFIX)), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $secret;
    }
}
