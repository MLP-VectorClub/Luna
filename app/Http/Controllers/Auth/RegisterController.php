<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Rules\StrictEmail;
use App\Rules\Username;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Valorin\Pwned\Pwned;

class RegisterController extends Controller
{
    /**
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     * @throws \Illuminate\Validation\ValidationException
     */
    public function viaPassword(Request $request)
    {
        $is_sanctum = $request->attributes->get('sanctum') === true;
        if ($is_sanctum && $request->user() !== null) {
            abort(403);
        }

        $have_users = User::any();
        $validator = Validator::make($request->only(['email', 'name', 'password']), [
            'name' => [
                'required',
                'string',
                'min:5',
                'max:20',
                'unique:users',
                new Username(),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'min:3',
                'unique:users',
                new StrictEmail(),
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:300',
                new Pwned,
            ],
        ]);

        // TODO remove when registration for the public is open
        if ($have_users) {
            abort(503, 'New registrations are currently not accepted, thank you for your understanding.');
            throw new ValidationException($validator);
        }

        $data = $validator->validate();
        // First user will receive developer privileges
        if (!$have_users) {
            $data['role'] = 'developer';
        }

        // Hash password
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        if ($is_sanctum) {
            Auth::login($user, true);
            return response()->noContent();
        }

        return $user->authResponse();
    }
}
