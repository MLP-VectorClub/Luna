<?php

namespace App\Rules;

use EmailValidator\Validator as EmailValidator;

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

        $validator = new EmailValidator();

        return $validator->isEmail($value);
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
