<?php

namespace App\Rules;

use App\Utils\EnumWrapper;
use Illuminate\Validation\Rules\In;
use TypeError;

class EnumValue extends In
{
    /**
     * Create a new rule instance.
     *
     * @param  string  $class
     */
    public function __construct(string $class)
    {
        if (!method_exists($class, 'getValues')) {
            throw new TypeError("Argument 1 passed to ".__METHOD__." must point to a class that extends ".EnumWrapper::class);
        }

        parent::__construct($class::getValues());
    }
}
