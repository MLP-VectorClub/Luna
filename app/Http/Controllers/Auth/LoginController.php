<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;

/**
 * @OA\Get(
 *     path="/users/tokens",
 *     description="Returns a list of access tokens that belong to the current user",
 *     tags={"authentication"},
 *     security={"bearerAuth"},
 *     @OA\Response(
 *         response="200",
 *         description="Sucess",
 *         @OA\JsonContent(
 *             additionalProperties=false,
 *         )
 *     ),
 *     @OA\Response(
 *         response="401",
 *         description="Unathorized",
 *     )
 * )
 * @OA\Delete(
 *     path="/users/tokens/{id}",
 *     description="Deletes an access token that belongs to the current user",
 *     tags={"authentication"},
 *     security={"bearerAuth"},
 *     @OA\Parameter(
 *         in="path",
 *         name="id",
 *         required=true,
 *         @OA\Schema(
 *             type="string",
 *             format="hex"
 *         ),
 *         description="The ID of the token to delete"
 *     ),
 *     @OA\Response(
 *         response="200",
 *         description="Sucess",
 *         @OA\JsonContent(
 *             additionalProperties=false,
 *         )
 *     ),
 *     @OA\Response(
 *         response="404",
 *         description="Token not found",
 *     ),
 *     @OA\Response(
 *         response="401",
 *         description="Unathorized",
 *     )
 * )
 */
class LoginController extends Controller
{
    /**
     * @OA\Schema(
     *     schema="AccessTokenResponse",
     *     type="object",
     *     required={
     *         "access_token"
     *     },
     *     additionalProperties=false,
     *     @OA\Property(
     *         property="access_token",
     *         description="Long-lived access token for the user (also sent inside a `auth_token` cookie)",
     *         type="string",
     *         format="JWT"
     *     )
     * )
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
     *         @OA\JsonContent(ref="#/components/schemas/AccessTokenResponse")
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Invalid credentials",
     *     )
     * )
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     * @throws \Illuminate\Validation\ValidationException
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

        if (!Hash::check($data['password'], $user->password)) {
            abort(401);
        }

        return $user->authResponse();
    }
}
