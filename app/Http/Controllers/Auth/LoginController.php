<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;

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
     *         response="200",
     *         description="Authentication successful",
     *         @OA\JsonContent(
     *             additionalProperties=false,
     *             @OA\Property(
     *                 property="token",
     *                 type="string"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response="204",
     *         description="Session-based authentication successful (authentication via cookies, no token is sent)"
     *     ),
     *     @OA\Response(
     *         response="403",
     *         description="Already logged in via session-based authentication",
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Invalid credentials",
     *     )
     * )
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     * @throws \Illuminate\Validation\ValidationException
     */
    public function viaPassword(Request $request)
    {
        $is_airlock = $request->attributes->get('airlock') === true;
        if ($is_airlock && $request->user() !== null) {
            abort(403);
        }

        $data = Validator::make($request->only(['email', 'password']), [
            'email' => 'required|string',
            'password' => 'required|string',
        ])->validate();

        $user = User::whereEmail($data['email'])->first();
        if (empty($user)) {
            abort(401);
        }

        if (!Hash::check($data['password'], $user->password)) {
            abort(401);
        }

        if ($is_airlock) {
            Auth::login($user, true);
            return response()->noContent();
        }

        return $user->authResponse();
    }
}
