<?php

namespace App\Http\Controllers;

use App\Rules\StrictEmail;
use App\Rules\Username;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;
use Valorin\Pwned\Pwned;

class UsersController extends Controller
{

    /**
     * @OA\Schema(
     *     schema="UserRole",
     *     type="string",
     *     description="List of roles a user can have",
     *     enum={"guest","user","member","assistant","staff","admin","developer"}
     * )
     * @OA\Schema(
     *     schema="AvatarProvider",
     *     type="string",
     *     description="List of supported avatar providers",
     *     enum={"deviantart","discord"}
     * )
     * @OA\Schema(
     *     schema="User",
     *     type="object",
     *     description="Represents an authenticated user",
     *     required={
     *         "id",
     *         "name",
     *         "displayName",
     *         "role",
     *         "avatarUrl",
     *         "avatarProvider"
     *     },
     *     additionalProperties=false,
     *     @OA\Property(
     *         property="id",
     *         type="integer",
     *         minimum=1
     *     ),
     *     @OA\Property(
     *         property="name",
     *         type="string",
     *         example="example"
     *     ),
     *     @OA\Property(
     *         property="displayName",
     *         type="string",
     *         example="example"
     *     ),
     *     @OA\Property(
     *         property="role",
     *         ref="#/components/schemas/UserRole",
     *     ),
     *     @OA\Property(
     *         property="avatarUrl",
     *         type="string",
     *         format="uri",
     *         example="https://a.deviantart.net/avatars/e/x/example.png"
     *     ),
     *     @OA\Property(
     *         property="avatarProvider",
     *         ref="#/components/schemas/AvatarProvider"
     *     )
     * )
     * @OA\Get(
     *     path="/users/me",
     *   description="Get information about the currently logged in user",
     *     tags={"authentication"},
     *     @OA\Response(
     *         response="200",
     *         description="Query successful",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(
     *                     schema="ValueOfUser",
     *                     type="object",
     *                     description="A user's data under the user key",
     *                     required={
     *                         "user"
     *                     },
     *                     additionalProperties=false,
     *                     @OA\Property(
     *                         property="user",
     *                         type="object",
     *                         ref="#/components/schemas/User"
     *                     )
     *                 ),
     *                 @OA\Schema(
     *                   schema="SessionUpdating",
     *                   type="object",
     *                   required={
     *                     "sessionUpdating"
     *                   },
     *                   additionalProperties=false,
     *                   @OA\Property(
     *                     property="sessionUpdating",
     *                     type="boolean",
     *                     description="If this value is true the DeviantArt access token expired and the backend is updating it in the background. Future requests should be made to the appropriate endpoint periodically (TODO) to check whether the session update was successful and the user should be logged out if it wasn't."
     *                   )
     *                 )
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Unathorized",
     *     )
     * )
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
            // TODO Make dynamic
            'session_updating' => false,
        ]);
    }
}
