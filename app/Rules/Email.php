<?php

namespace App\Rules;

use Illuminate\Support\Facades\Validator;

/**
 * Lax e-mail validator for logins, only checks for basic syntax
 *
 * @package App\Rules
 */
class Email extends BaseRule
{
    public const MAXIMUM_LENGTH = 128;

    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute
     * @param mixed $value
     *
     * @return bool
     */
    public function passes($attribute, $value)
    {
        if (!\is_string($value)) {
            return false;
        }
        if (\strlen($value) > self::MAXIMUM_LENGTH) {
            return false;
        }

        return Validator::make(['email' => $value], ['email' => 'email:rfc'])->passes();
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return trans('validation.email');
    }
}
