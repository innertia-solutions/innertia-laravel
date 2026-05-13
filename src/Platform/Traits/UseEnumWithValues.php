<?php

namespace Innertia\Platform\Traits;

trait UseEnumWithValues
{
    /**
     * Obtiene todos los valores disponibles como array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Obtiene todos los casos como array asociativo [name => value]
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->name] = $case->value;
        }

        return $options;
    }
}
