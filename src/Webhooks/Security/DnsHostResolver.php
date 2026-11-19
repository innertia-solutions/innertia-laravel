<?php

namespace Innertia\Webhooks\Security;

final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = [];

        foreach (@gethostbynamel($host) ?: [] as $ip) {
            $ips[] = $ip;
        }

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
