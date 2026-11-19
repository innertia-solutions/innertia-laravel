<?php

namespace Innertia\Mail\UseCases;

use Innertia\Mail\MailSettings;
use Innertia\Platform\Contracts\UseCase;

/** Saves the platform mail settings (already validated) and applies them to this process. */
class SaveMailSettings extends UseCase
{
    public function __construct(public readonly array $data) {}

    public function execute(): array
    {
        $result = MailSettings::save($this->data);
        MailSettings::apply();

        return $result;
    }
}
