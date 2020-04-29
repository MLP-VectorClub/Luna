<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class LoginController extends Controller
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
            return response()->noContent();
        }

        $data = Validator::make($request->only(['email', 'password']), [
            'email' => 'required|string',
            'password' => 'required|string',
        ])->validate();

        $user = User::whereEmail($data['email'])->first();

        $password = empty($user) ? '' : $user->password;

        if (!Hash::check($data['password'], $password)) {
            abort(401);
        }

        if ($is_sanctum) {
            Auth::login($user, true);
            return response()->noContent();
        }

        return $user->authResponse();
    }
}
