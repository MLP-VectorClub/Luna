<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;

class LoginController extends Controller
{
    /**
     * @OA\Schema(
     *     schema="LoginRequest",
     *     type="object",
     *     required={
     *         "email",
     *         "password"
     *     },
     *     additionalProperties=false,
     *     @OA\Property(
     *         property="email",
     *         type="string"
     *     ),
     *     @OA\Property(
     *         property="password",
     *         type="string"
     *     )
     * )
     * @OA\Post(
     *     path="/users/login",
     *     description="Used for obtaining an API access token",
     *     tags={"authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/LoginRequest")
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
     *         description="Could not sign in, check the error message for details",
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
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

        if ($password === null) {
            return response()->json(['message' => trans('errors.auth.no_password_set')], 403);
        }

        if (!Hash::check($data['password'], $password)) {
            abort(401);
        }

        if ($is_sanctum) {
            // Disable remembering users while a read-only DB user isu sed to avoid errors when writing remember_token
            $remember = !Str::endsWith(config('database.connections.mysql.username'), '_ro');
            Auth::login($user, $remember);
            return response()->noContent();
        }

        return $user->authResponse();
    }
}
