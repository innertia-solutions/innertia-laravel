<?php

namespace Innertia\Webhooks\Security;

use RuntimeException;

/** The webhook URL is not allowed (scheme, credentials, private/loopback/metadata address, unresolvable). */
final class UnsafeWebhookUrl extends RuntimeException {}
