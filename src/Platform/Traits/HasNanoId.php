<?php

namespace Innertia\Platform\Traits;

use Hidehalo\Nanoid\Client;

trait HasNanoId
{
    public static function bootHasNanoId(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (new Client)->generateId(size: 21);
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
