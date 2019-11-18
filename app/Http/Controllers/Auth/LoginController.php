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
     * @OA\Post(
     *     path="/users/login",
     *     description="Used for obtaining an API access token",
     *     tags={"authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 schema="LoginRequest",
     *                 type="object",
     *                 required={
     *                     "email",
     *                     "password"
     *                 },
     *                 additionalProperties=false,
     *                 @OA\Property(
     *                     property="email",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="password",
     *                     type="string"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response="204",
     *         description="Authentication successful",
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Invalid credentials",
     *     )
     * )
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function viaPassword(Request $request)
    {
        $data = Validator::make($request->only(['email', 'password']), [
            'email' => 'required|string',
            'password' => 'required|string',
        ])->validate();

        $user = User::whereEmail($data['email'])->first();
        if (empty($user)) {
            abort(401);
        }

        if (!Hash::check($user->password, Hash::make($data['password']))) {
            abort(401);
        }

        $cookie = $user->createAuthCookie('Login');
        return response()->noContent()->withCookie($cookie);
    }
}
