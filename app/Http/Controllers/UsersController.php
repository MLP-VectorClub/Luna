<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Laravel\Passport\Passport;
use Laravel\Passport\TokenRepository;
use OpenApi\Annotations as OA;

class UsersController extends Controller
{
    /**
     * The token repository implementation.
     *
     * @var \Laravel\Passport\TokenRepository
     */
    protected $tokenRepository;

    /**
     * Create a controller instance.
     *
     * @param  \Laravel\Passport\TokenRepository  $tokenRepository
     * @return void
     */
    public function __construct(TokenRepository $tokenRepository)
    {
        $this->tokenRepository = $tokenRepository;
    }

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
     *         minimum=1,
     *         example=1
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
     *         example="user",
     *         @OA\Schema(ref="#/components/schemas/UserRole")
     *     ),
     *     @OA\Property(
     *         property="avatarUrl",
     *         type="string",
     *         format="uri",
     *         example="https://a.deviantart.net/avatars/e/x/example.png",
     *         nullable=true,
     *     ),
     *     @OA\Property(
     *         property="avatarProvider",
     *         ref="#/components/schemas/AvatarProvider"
     *     )
     * )
     * @OA\Schema(
     *     schema="ValueOfUser",
     *     type="object",
     *     required={
     *         "user"
     *     },
     *     additionalProperties=false,
     *     @OA\Property(
     *         property="user",
     *         ref="#/components/schemas/User"
     *     )
     * ),
     * @OA\Schema(
     *   schema="SessionUpdating",
     *   type="object",
     *   required={
     *     "sessionUpdating"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="sessionUpdating",
     *     type="boolean",
     *     description="If this value is true the DeviantArt access token expired and the backend is updating it in the background. Future requests should be made to the appropriate endpoint periodically (TODO) to check whether the session update was successful and the user should be logged out if it wasn't."
     *   )
     * )
     * @OA\Get(
     *     path="/users/me",
     *     description="Get information about the currently logged in user",
     *     tags={"authentication"},
     *     security={"bearerAuth":{}},
     *     @OA\Response(
     *         response="200",
     *         description="Query successful",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/ValueOfUser"),
     *                 @OA\Schema(ref="#/components/schemas/SessionUpdating")
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

    /**
     * @OA\Post(
     *     path="/users/logout",
     *     description="Shortcut for calling the token DELETE endpoint with the current token",
     *     tags={"authentication"},
     *     security={"bearerAuth":{}},
     *     @OA\Response(
     *         response="204",
     *         description="Logout successful"
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Unathorized",
     *     )
     * )
     *
     * @param  Request  $request
     * @return Response
     */
    public function logout(Request $request)
    {
        $token = $request->bearerToken();
        $token_id = (new \Lcobucci\JWT\Parser())->parse($token)->getHeader('jti');
        $token = $this->tokenRepository->findForUser(
            $token_id,
            $request->user()->getKey()
        );

        if ($token === null) {
            abort(404);
        }

        $token->revoke();

        $delete_cookie = Cookie::forget(Passport::cookie());

        return response()->noContent()->withCookie($delete_cookie);
    }
}
