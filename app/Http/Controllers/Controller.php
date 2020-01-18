<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use OpenApi\Annotations as OA;

/**
 * @OA\OpenApi(
 *     @OA\Info(
 *         title="MLP Vector Club API",
 *         version="0.1",
 *         description="A work-in-progress API for the [MLP Vector Club](https://mlpvector.club/)'s website.",
 *         @OA\License(name="MIT"),
 *         @OA\Contact(name="David Joseph Guzsik", url="https://seinopsys.dev", email="seinopsys@gmail.com"),
 *     ),
 *     @OA\Server(url="/v0", description="Unstable API"),
 *     @OA\Tag(name="authentication", description="Endpoints related to getting a user logged in or out, as well as checking logged in status"),
 * )
 * @OA\Schema(
 *     schema="ErrorResponse",
 *     type="object",
 *     required={
 *         "message"
 *     },
 *     additionalProperties=false,
 *     @OA\Property(
 *         property="message",
 *         type="string",
 *         description="An error message describing what caused the request to fail",
 *         example="The given data was invalid."
 *     )
 * )
 * @OA\Schema(
 *     schema="ValidationErrorResponse",
 *     allOf={
 *         @OA\Schema(
 *             type="object",
 *             required={
 *                 "errors"
 *             },
 *             additionalProperties=false,
 *             @OA\Property(
 *                 property="errors",
 *                 type="object",
 *                 description="A map containing error messages for each field that did not pass validation",
 *                 minProperties=1,
 *                 @OA\AdditionalProperties(
 *                     type="array",
 *                     minItems=1,
 *                     @OA\Items(type="string")
 *                 ),
 *                 example={
 *                     "username": {"The username must be at least 8 characters long", "The username is already taken"},
 *                     "email": {"The email must be at least 3 characters long"},
 *                 }
 *             )
 *         ),
 *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
 *     }
 * )
 * @OA\Get(
 *     path="/../airlock/csrf-cookie",
 *     description="Initialize CSRF protection by sending a dummy request through the web middleware. Used only for session-based authentication.",
 *     tags={"authentication"},
 *     @OA\Response(
 *         response="204",
 *         description="Sucess"
 *     )
 * )
 */
class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
}
