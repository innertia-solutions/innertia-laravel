<?php

namespace Innertia\Webhooks\Security;

/** Resolves a host name to its IPv4/IPv6 addresses (bound in the container so tests can fake DNS). */
interface HostResolver
{
    /** @return string[] IP addresses (empty when it does not resolve) */
    public function resolve(string $host): array;
}
