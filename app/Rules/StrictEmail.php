<?php

namespace App\Rules;

use Illuminate\Support\Facades\Validator;

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

        // Laravel's email rule considers an empty string as nothing to validate
        if ($value === '') {
            return false;
        }

        // The DNS lookup can be switched off for tests that must not depend on the network
        return Validator::make(['email' => $value], ['email' => config('app.email_dns_check', true) ? 'email:rfc,dns' : 'email:rfc'])->passes();
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
