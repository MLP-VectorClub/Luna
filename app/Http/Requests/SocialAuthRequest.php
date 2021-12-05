<?php

namespace App\Http\Requests;

use App\Enums\SocialProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SocialAuthRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return !Auth::check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function validator()
    {
        return Validator::make(['provider' => $this->route('provider')], [
            'provider' => ['required', Rule::in(SocialProvider::values())],
        ]);
    }

    /**
     * @inheritDoc
     * @return array = [
     *     'provider' => SocialProvider::cases(),
     * ]
     */
    public function validated()
    {
        return parent::validated();
    }
}
