<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Rules\StrictEmail;
use App\Rules\Username;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations\JsonContent;
use Valorin\Pwned\Pwned;

class RegisterController extends Controller
{
    /**
     * @OA\Schema(
     *     schema="RegistrationRequest",
     *     type="object",
     *     required={
     *         "name",
     *         "email",
     *         "password"
     *     },
     *     additionalProperties=false,
     *     @OA\Property(
     *         property="name",
     *         type="string",
     *         minLength=5,
     *         maxLength=20,
     *     ),
     *     @OA\Property(
     *         property="email",
     *         type="string",
     *         minLength=3,
     *         maxLength=128,
     *     ),
     *     @OA\Property(
     *         property="password",
     *         type="string",
     *         minLength=8,
     *         maxLength=300,
     *     )
     * )
     * @OA\Post(
     *     path="/users",
     *     description="Register an account on the site",
     *     tags={"authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/RegistrationRequest")
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Registration successful",
     *         @OA\JsonContent(ref="#/components/schemas/AccessTokenResponse")
     *     ),
     *     @OA\Response(
     *         response="400",
     *         description="Validation error",
     *         @OA\JsonContent(
     *             ref="#/components/schemas/ValidationErrorResponse"
     *         )
     *     )
     * )
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     * @throws \Illuminate\Validation\ValidationException
     */
    public function viaPassword(Request $request)
    {
        $data = Validator::make($request->only(['email', 'name', 'password']), [
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
        ])->validate();

        // First user will receive developer privileges
        if (!User::any()) {
            $data['role'] = 'developer';
        }

        $user = User::create($data);
        return $user->authResponse();
    }
}
