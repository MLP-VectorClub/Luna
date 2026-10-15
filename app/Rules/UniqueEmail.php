<?php

namespace App\Rules;

use App\Models\User;
use Illuminate\Contracts\Validation\Rule;

/** `unique:users` for the e-mail address, which is stored encrypted: goes by the keyed hash, case does not matter */
class UniqueEmail implements Rule
{
    public function __construct(private readonly ?int $ignoreUserId = null)
    {
    }

    public function passes($attribute, $value): bool
    {
        if (!is_string($value) || $value === '') {
            return true;
        }

        return !User::whereEmail($value)->when($this->ignoreUserId !== null, fn($q) => $q->where('id', '!=', $this->ignoreUserId))->exists();
    }

    public function message(): string
    {
        return trans('validation.unique', ['attribute' => 'email']);
    }
}
