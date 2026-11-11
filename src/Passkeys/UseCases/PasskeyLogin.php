<?php

namespace Innertia\Passkeys\UseCases;

use Innertia\Auth\UseCases\Login;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Exceptions\PlatformAccountException;
use Innertia\Platform\Contracts\UseCase;

class PasskeyLogin extends UseCase
{
    /** @param list<string> $contexts en orden de preferencia (login único por probing) */
    public function __construct(
        public readonly string $key,
        public readonly array $response,
        public readonly array $contexts,
    ) {}

    public function execute(): array
    {
        $user = (new VerifyPasskeyAssertion($this->key, $this->response))->execute();

        if (config('innertia.platform.separate_identity') && $user->is_platform_admin) {
            throw new PlatformAccountException();
        }

        $known = config('innertia.contexts', []);
        foreach ($this->contexts as $context) {
            if (! array_key_exists($context, $known)) {
                continue;
            }
            if (method_exists($user, 'hasContext') && ! $user->hasContext($context)) {
                continue;
            }

            return Login::issueFor($user, $context, strongAuth: true) + ['context' => $context];
        }

        throw new ForbiddenException('Esta cuenta no tiene acceso a esta aplicación.');
    }
}
