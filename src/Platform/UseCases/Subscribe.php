<?php

namespace Innertia\Platform\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Exceptions\NotFoundException;
use Innertia\Models\Subscription;
use Innertia\Platform\Contracts\UseCase;

class Subscribe extends UseCase
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string          $subscribableType,
        public readonly string          $subscribableId,
        public readonly array           $events   = ['*'],
        public readonly array           $channels = ['mail'],
    ) {}

    public function execute(): Subscription
    {
        $model = app($this->subscribableType);

        $subscribable = $model::find($this->subscribableId);

        if (! $subscribable) {
            throw new NotFoundException("{$this->subscribableType} [{$this->subscribableId}] not found.");
        }

        return $subscribable->subscribe($this->user, $this->events, $this->channels);
    }
}
