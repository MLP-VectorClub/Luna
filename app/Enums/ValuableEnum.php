<?php

namespace App\Enums;

trait ValuableEnum {
    public static function values(): array
    {
        /**
         * @var null|string[]
         */
        static $values = null;

        if ($values === null) {
            $values = array_column(self::cases(), 'value');
        }

        return $values;
    }
}
