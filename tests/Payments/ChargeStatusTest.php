<?php

use Innertia\Payments\Enums\ChargeStatus;

it('permite y rechaza transiciones según la máquina de estados', function () {
    expect(ChargeStatus::Pending->canTransitionTo(ChargeStatus::Paid))->toBeTrue()
        ->and(ChargeStatus::Pending->canTransitionTo(ChargeStatus::Failed))->toBeTrue()
        ->and(ChargeStatus::Paid->canTransitionTo(ChargeStatus::Refunded))->toBeTrue()
        ->and(ChargeStatus::Paid->canTransitionTo(ChargeStatus::Pending))->toBeFalse()
        ->and(ChargeStatus::Refunded->canTransitionTo(ChargeStatus::Paid))->toBeFalse();
});
