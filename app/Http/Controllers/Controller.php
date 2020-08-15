<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use OpenApi\Annotations as OA;

/**
 * @OA\OpenApi(
 *   @OA\Info(
 *     title="MLP Vector Club API",
 *     version="0.2",
 *     description="A work-in-progress API for the [MLP Vector Club](https://mlpvector.club/)'s website.",
 *     @OA\License(name="MIT"),
 *     @OA\Contact(name="David Joseph Guzsik", url="https://seinopsys.dev", email="david@seinopsys.dev"),
 *   ),
 *   @OA\Server(url="/", description="Current Host"),
 *   @OA\Tag(name="authentication", description="Endpoints related to getting a user logged in or out, as well as checking logged in status"),
 *   @OA\Tag(name="appearances", description="Working with entries in the color guide"),
 *   @OA\Tag(name="server info", description="For diagnostic or informational data")
 * )
 * @OA\Schema(
 *   schema="ErrorResponse",
 *   type="object",
 *   required={
 *     "message"
 *   },
 *   additionalProperties=false,
 *   @OA\Property(
 *     property="message",
 *     type="string",
 *     description="An error message describing what caused the request to fail",
 *     example="The given data was invalid."
 *   )
 * )
 * @OA\Schema(
 *   schema="IsoStandardDate",
 *   type="string",
 *   format="date-time",
 *   description="An ISO 8601 standard compliant date as a string"
 * )
 * @OA\Schema(
 *   schema="ValidationErrorResponse",
 *   allOf={
 *     @OA\Schema(
 *       type="object",
 *       required={
 *         "errors"
 *       },
 *       additionalProperties=false,
 *       @OA\Property(
 *         property="errors",
 *         type="object",
 *         description="A map containing error messages for each field that did not pass validation",
 *         minProperties=1,
 *         @OA\AdditionalProperties(
 *           type="array",
 *           minItems=1,
 *           @OA\Items(type="string")
 *         ),
 *         example={
 *           "field1": {"The field1 field must be a string", "The field1 field must be at least 5 characters long"},
 *           "field2": {"The field2 field does not exist in one / two / three"},
 *         }
 *       )
 *     ),
 *     @OA\Schema(ref="#/components/schemas/ErrorResponse")
 *   }
 * )
 * @OA\Get(
 *   path="/sanctum/csrf-cookie",
 *   description="Initialize CSRF protection by sending a dummy request through the web middleware. Used only for session-based authentication.",
 *   tags={"authentication"},
 *   @OA\Response(
 *     response="204",
 *     description="Sucess"
 *   )
 * )
 * @OA\Schema(
 *   schema="PageNumber",
 *   type="integer",
 *   minimum=1,
 *   default=1,
 *   description="A query parameter used for specifying which page is currently being displayed"
 * )
 * @OA\Schema(
 *   schema="File",
 *   type="string",
 *   format="binary",
 *   example=""
 * )
 * @OA\Schema(
 *   schema="SVGFile",
 *   type="string",
 *   format="svg",
 * )
 * @OA\Schema(
 *   schema="QueryString",
 *   type="string",
 *   default=""
 * )
 * @OA\Schema(
 *   schema="OneBasedId",
 *   type="integer",
 *   minimum=1,
 *   example=1
 * )
 * @OA\Schema(
 *   schema="ZeroBasedId",
 *   type="integer",
 *   minimum=0,
 *   example=1
 * )
 */
class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
}
