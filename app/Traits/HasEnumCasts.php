<?php

namespace App\Traits;

use BenSampo\Enum\Enum;

trait HasEnumCasts
{
    public function toArray(): array
    {
        $result = parent::toArray();
        foreach ($result as &$value) {
            if ($value instanceof Enum) {
                $value = $value->value;
            }
        }
        return $result;
    }
}
