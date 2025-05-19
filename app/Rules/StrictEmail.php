<?php

namespace App\Rules;

use EmailValidator\Validator as EmailValidator;

/**
 * Strict e-mail validator for new registrations or e-mail changes
 *
 * @package App\Rules
 */
class StrictEmail extends BaseRule
{

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
        if (\strlen($value) > Email::MAXIMUM_LENGTH) {
            return false;
        }

        $validator = new EmailValidator();

        return $validator->isValid($value);
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
