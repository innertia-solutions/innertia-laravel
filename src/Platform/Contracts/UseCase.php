<?php

namespace Innertia\Platform\Contracts;

abstract class UseCase
{
    abstract public function execute(): mixed;
}
