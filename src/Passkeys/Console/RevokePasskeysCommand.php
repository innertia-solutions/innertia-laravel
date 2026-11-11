<?php

namespace Innertia\Passkeys\Console;

use Illuminate\Console\Command;
use Innertia\Passkeys\UseCases\RevokePasskeys;

class RevokePasskeysCommand extends Command
{
    protected $signature = 'passkeys:revoke {email : Correo de la cuenta}';

    protected $description = 'Revoca todas las passkeys de una cuenta (recuperación, dispositivo perdido).';

    public function handle(): int
    {
        $model = config('auth.providers.users.model');
        $user = $model::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No existe una cuenta con ese correo.');

            return self::FAILURE;
        }

        $count = (new RevokePasskeys((string) $user->getAuthIdentifier(), null, 'console'))->execute();
        $this->info("{$count} passkey(s) revocada(s) para {$user->email}.");

        return self::SUCCESS;
    }
}
