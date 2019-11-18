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
 *         @OA\Contact(name="David Joseph Guzsik", url="https://seinopsys.hu", email="seinopsys@gmail.com"),
 *     ),
 *     @OA\Server(url="/v0", description="Unstable API"),
 *     @OA\Tag(name="authentication", description="Endpoints related to getting a user logged in or out, as well as checking logged in status"),
 * )
 * @OA\Schema(
 *     schema="ValidationErrorResponse",
 *     type="object",
 *     required={
 *         "errors"
 *     },
 *     additionalProperties=false,
 *     @OA\Property(
 *         property="errors",
 *         type="object",
 *         minProperties=1,
 *         @OA\AdditionalProperties(
 *             type="array",
 *             minItems=1,
 *             @OA\Items(type="string")
 *         ),
 *         example={
 *             "first_field": {"Validation error message"},
 *             "second_field": {"Validation error message"},
 *         }
 *     )
 * )
 */
class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
}
