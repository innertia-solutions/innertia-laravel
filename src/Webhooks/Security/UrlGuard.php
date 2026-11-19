<?php

namespace Innertia\Webhooks\Security;

/**
 * Anti-SSRF guard for webhook URLs. Evaluated when a subscription is saved AND on every delivery (DNS is
 * resolved again each time, and the connection is pinned to the checked IP so a rebinding cannot swap it).
 *
 * Blocks: non-https (unless innertia.webhooks.allow_http), credentials in the URL, IP literals or names that
 * resolve to private, loopback, link-local, CGNAT, multicast, reserved, documentation or cloud metadata
 * addresses (unless innertia.webhooks.allow_private_networks — local development only).
 */
final class UrlGuard
{
    /** CIDR blocks that are never valid webhook targets. */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
    ];

    private const BLOCKED_V6 = [
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    private const BLOCKED_HOSTS = ['localhost', 'metadata.google.internal', 'metadata', 'instance-data'];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @return array{host: string, port: int, ip: string} the checked target (ip to pin the connection to)
     *
     * @throws UnsafeWebhookUrl
     */
    public function check(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            throw new UnsafeWebhookUrl('La URL no es válida.');
        }

        $scheme = strtolower($parts['scheme']);
        $allowHttp = (bool) config('innertia.webhooks.allow_http', false);

        if ($scheme !== 'https' && ! ($allowHttp && $scheme === 'http')) {
            throw new UnsafeWebhookUrl('La URL debe usar https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeWebhookUrl('La URL no puede incluir credenciales.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $allowPrivate = (bool) config('innertia.webhooks.allow_private_networks', false);

        if (! $allowPrivate && (in_array($host, self::BLOCKED_HOSTS, true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal'))) {
            throw new UnsafeWebhookUrl('La URL apunta a una dirección interna.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolver->resolve($host);

        if ($ips === []) {
            throw new UnsafeWebhookUrl('No se pudo resolver el dominio de la URL.');
        }

        if (! $allowPrivate) {
            foreach ($ips as $ip) {
                if (self::isBlockedIp($ip)) {
                    throw new UnsafeWebhookUrl('La URL apunta a una red privada o reservada.');
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /** Same as check() but returns the error message instead of throwing (validation). */
    public function problem(string $url): ?string
    {
        try {
            $this->check($url);

            return null;
        } catch (UnsafeWebhookUrl $e) {
            return $e->getMessage();
        }
    }

    public static function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv4-mapped (::ffff:a.b.c.d) → evaluate the embedded IPv4 too.
            $bin = inet_pton($ip);
            if ($bin !== false && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
                return true;
            }

            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return true;
                }
            }

            return false;
        }

        return true; // not an IP → refuse
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;

        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subnetBin[$bytes]) & ord($mask));
    }
}
