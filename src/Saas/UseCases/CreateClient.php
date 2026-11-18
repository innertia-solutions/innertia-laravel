<?php

namespace Innertia\Saas\UseCases;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Saas\Models\Client;

class CreateClient extends UseCase
{
    /** Attributes a caller may set (key and name go through the constructor). */
    public const EDITABLE = [
        'legal_name', 'tax_id', 'business_activity', 'address', 'billing_email',
        'invoicing_mode', 'payment_terms_days', 'grace_days', 'late_fee_percent', 'status', 'notes',
    ];

    public function __construct(
        public readonly string  $name,
        public readonly ?string $key = null,
        public readonly array   $attributes = [],
    ) {}

    public function execute(): Client
    {
        $key = $this->key ?? self::uniqueKey($this->name);

        if (Client::where('key', $key)->exists()) {
            throw new ConflictException("A client with key \"{$key}\" already exists.");
        }

        return Client::create([
            ...Arr::only($this->attributes, self::EDITABLE),
            'key'  => $key,
            'name' => $this->name,
        ]);
    }

    public static function uniqueKey(string $name): string
    {
        $base = Str::slug($name) ?: 'client';
        $key = $base;
        $i = 1;
        while (Client::where('key', $key)->exists()) {
            $key = $base . '-' . (++$i);
        }

        return $key;
    }
}
