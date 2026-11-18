<?php

namespace Innertia\Saas\Enums;

enum TenantEnvironment: string
{
    case Production = 'production';
    case Testing    = 'testing';

    public function label(): string
    {
        return match ($this) {
            self::Production => 'Producción',
            self::Testing    => 'Pruebas',
        };
    }
}
