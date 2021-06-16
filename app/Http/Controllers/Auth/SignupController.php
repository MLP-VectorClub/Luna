<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Utils\AccountHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SignupController extends Controller
{
    /**
     * @OA\Schema(
     *   schema="RegistrationRequest",
     *   type="object",
     *   required={
     *     "name",
     *     "email",
     *     "password",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="name",
     *     type="string",
     *     minLength=5,
     *     maxLength=20,
     *   ),
     *   @OA\Property(
     *     property="email",
     *     type="string",
     *     minLength=3,
     *     maxLength=128,
     *   ),
     *   @OA\Property(
     *     property="password",
     *     type="string",
     *     format="password",
     *     minLength=8,
     *     maxLength=300,
     *   )
     * )
     * @OA\Post(
     *   path="/users",
     *   description="Register an account on the site",
     *   tags={"authentication"},
     *   security={},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(ref="#/components/schemas/RegistrationRequest")
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="Registration successful",
     *     @OA\JsonContent(
     *       additionalProperties=false,
     *       @OA\Property(
     *         property="token",
     *         type="string"
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response="204",
     *     description="Registration successful (authentication via cookies, no token is sent)"
     *   ),
     *   @OA\Response(
     *     response="400",
     *     description="Validation error",
     *     @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse")
     *   ),
     *   @OA\Response(
     *     response="403",
     *     description="Already logged in via session-based authentication",
     *   ),
     *   @OA\Response(
     *     response="503",
     *     description="Registrations are not possible at the moment",
     *     @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *   )
     * )
     *
     * @param  Request  $request
     * @return JsonResponse|Response
     */
    public function viaPassword(Request $request)
    {
        if (AccountHelper::isSanctum($request) && $request->user() !== null) {
            abort(403);
        }

        AccountHelper::validator($request->all())->validate();

        $user = AccountHelper::create($request->all());
        return AccountHelper::authResponse($request, $user, true);
    }

    public function viaSocialite()
    {
        abort(403, "Social signup is not implemented yet");
    }
}
