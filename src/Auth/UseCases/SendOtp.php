<?php

namespace Innertia\Auth\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Auth\Services\OtpService;
use Innertia\Platform\Contracts\UseCase;

class SendOtp extends UseCase
{
    public function __construct(protected OtpService $otp) {}

    public function execute(Authenticatable $user, string $action): void
    {
        $this->otp->send($user, $action);
    }
}
