<?php

namespace Innertia\Auth\UseCases;

use Innertia\Auth\Services\OtpService;
use Innertia\Platform\Contracts\UseCase;

class SendOtp extends UseCase
{
    public function __construct(
        public readonly string $userId,
        public readonly string $action,
    ) {
       parent::__construct();
       
    }

    public function execute(): void
    {
        $model = config('auth.providers.users.model');
        $user  = $model::findOrFail($this->userId);

        app(OtpService::class)->send($user, $this->action);
    }
}
