<?php

namespace Innertia\Notifications\UseCases;

use Innertia\Notifications\Preferences;
use Innertia\Platform\Contracts\UseCase;

/** Saves a user's notice preferences (critical types are ignored by Preferences::save()). */
class SaveNotificationPreferences extends UseCase
{
    public function __construct(
        public readonly string $userId,
        public readonly array $items,
    ) {}

    public function execute(): array
    {
        Preferences::save($this->userId, $this->items);

        return Preferences::forUser($this->userId);
    }
}
