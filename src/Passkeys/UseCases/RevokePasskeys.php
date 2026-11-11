<?php

namespace Innertia\Passkeys\UseCases;

use Innertia\Passkeys\Models\Passkey;
use Innertia\Passkeys\Support\PasskeyLog;
use Innertia\Platform\Contracts\UseCase;

/** Revoca una passkey (id) o todas las del usuario (id null). Devuelve cuántas borró. */
class RevokePasskeys extends UseCase
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $passkeyId = null,
        public readonly ?string $actorId = null,
    ) {}

    public function execute(): int
    {
        $query = Passkey::where('user_id', $this->userId);
        if ($this->passkeyId !== null) {
            $query->whereKey($this->passkeyId);
        }

        $count = $query->delete();

        PasskeyLog::info('passkey.revoked', [
            'user_id'    => $this->userId,
            'passkey_id' => $this->passkeyId,
            'count'      => $count,
            'actor_id'   => $this->actorId,
        ]);

        return $count;
    }
}
