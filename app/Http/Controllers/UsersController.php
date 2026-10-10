<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\DeviantartUser;
use App\Models\User;
use App\Utils\DeviantArtTokens;
use App\Utils\Core;
use App\Utils\SettingsHelper;
use App\Utils\UserPrefHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Valorin\Pwned\Pwned;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use OpenApi\Annotations as OA;

class UsersController extends Controller
{
    /**
     * @OA\Schema(
     *   schema="BarePublicUser",
     *   type="object",
     *   description="Represents the absolute minimum info necessary to get a user profile URL",
     *   required={
     *     "id",
     *     "name",
     *     "role",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="id",
     *     type="integer",
     *     minimum=1,
     *     example=1,
     *   ),
     *   @OA\Property(
     *     property="name",
     *     type="string",
     *     example="example",
     *   ),
     *   @OA\Property(
     *     property="role",
     *     description="The publicly visible role for the user",
     *     ref="#/components/schemas/AccountRole",
     *   ),
     * )
     * @OA\Schema(
     *   schema="PublicUser",
     *   allOf={
     *     @OA\Schema(ref="#/components/schemas/BarePublicUser"),
     *     @OA\Schema(
     *       type="object",
     *       description="Represents a publicly accessible representation of a user",
     *       required={
     *         "avatarUrl",
     *         "avatarProvider",
     *       },
     *       additionalProperties=false,
     *       @OA\Property(
     *         property="avatarUrl",
     *         type="string",
     *         format="uri",
     *         example="https://a.deviantart.net/avatars/e/x/example.png",
     *         nullable=true,
     *       ),
     *       @OA\Property(
     *         property="avatarProvider",
     *         ref="#/components/schemas/AvatarProvider"
     *       ),
     *     )
     *   }
     * )
     * @OA\Schema(
     *   schema="CurrentUser",
     *   type="object",
     *   description="The signed in user as returned by `GET /users/me`, never includes the e-mail address",
     *   required={"id", "name", "role", "avatarUrl", "avatarProvider"},
     *   additionalProperties=false,
     *   @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *   @OA\Property(property="name", type="string", example="example"),
     *   @OA\Property(property="role", ref="#/components/schemas/DatabaseRole"),
     *   @OA\Property(property="avatarUrl", type="string", format="uri", nullable=true),
     *   @OA\Property(property="avatarProvider", ref="#/components/schemas/AvatarProvider"),
     *   @OA\Property(property="discordServerMember", type="boolean", description="Whether the user has a linked Discord account that is a member of the club's server, so the front end can leave out its invitation"),
     * )
     * @OA\Get(
     *   path="/users/me",
     *   description="Get information about the currently logged in user",
     *   tags={"authentication","users"},
     *   security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *   @OA\Response(
     *     response="200",
     *     description="Query successful",
     *     @OA\JsonContent(
     *       type="object",
     *       required={"user", "sessionUpdating"},
     *       additionalProperties=false,
     *       @OA\Property(property="user", ref="#/components/schemas/CurrentUser"),
     *       @OA\Property(property="sessionUpdating", type="boolean", description="Always false in Luna")
     *     )
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The DeviantArt session of a member is renewed here when it ran out (the old site did it on every request); when DeviantArt
        // refuses it the user has been signed out everywhere and has to sign in with DeviantArt again
        $da_user = DeviantArtTokens::enabled() ? $user->daUser : null;
        if ($da_user !== null && DeviantArtTokens::check($da_user) === DeviantArtTokens::REVOKED) {
            return response()->json(['message' => trans('errors.auth.deviantart_required'), 'deviantArtRequired' => true], 401);
        }

        return response()->camelJson([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'avatar_url' => $user->avatar_url,
                'avatar_provider' => $user->avatar_provider,
                'discord_server_member' => $user->discordMember !== null && $user->discordMember->access !== null && $user->discordMember->joined_at !== null,
            ],
            // Renewing the DeviantArt session happens above before the answer, nothing is left to wait for
            'session_updating' => false,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/users/da/{username}",
     *   description="Get on-site user information via a DeviantArt username",
     *   tags={"users"},
     *   security={},
     *   @OA\Parameter(
     *     in="path",
     *     name="username",
     *     required=true,
     *     @OA\Schema(
     *       type="string"
     *     ),
     *     description="The DeviantArt username to look for"
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="Query successful",
     *     @OA\JsonContent(ref="#/components/schemas/PublicUser")
     *   ),
     *   @OA\Response(
     *     response="404",
     *     description="No user found by this name"
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     *
     * @param  Request  $request
     * @param  string   $username
     * @return JsonResponse
     */
    public function getByName(Request $request, string $username): JsonResponse
    {
        /** @var DeviantartUser $da_user */
        $da_user = DeviantartUser::where('name', $username)->firstOrFail();
        /** @var User $user */
        $user = $da_user->user()->firstOrFail();
        return $this->getById($request, $user);
    }

    /**
     * @OA\Get(
     *   path="/users/da-uuid/{uuid}",
     *   operationId="GetUsersDaUuidUuid",
     *   description="Get the public information of a user by their DeviantArt account UUID. Developer role only.",
     *   tags={"users"},
     *   @OA\Parameter(in="path", name="uuid", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/User")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="User not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function getByDaUuid(Request $request, string $uuid): JsonResponse
    {
        /** @var DeviantartUser $da_user */
        $da_user = DeviantartUser::findOrFail(strtolower($uuid));
        /** @var User $user */
        $user = $da_user->user()->firstOrFail();

        return $this->getById($request, $user);
    }

    /**
     * @OA\Get(
     *   path="/users/{id}",
     *   description="Get information about the specified user",
     *   tags={"users"},
     *   security={},
     *   @OA\Parameter(
     *     in="path",
     *     name="id",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/OneBasedId")
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="Query successful",
     *     @OA\JsonContent(ref="#/components/schemas/PublicUser")
     *   ),
     *   @OA\Response(
     *     response="404",
     *     description="No user found by this ID"
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function getById(Request $request, User $user): JsonResponse
    {
        return response()->camelJson($user->publicResponse());
    }

    /**
     * @OA\Get(
     *   path="/users",
     *   description="Get a full list of users, i.e. those that have the 'user' role (requires staff permissions)",
     *   tags={"users"},
     *   security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *   @OA\Response(
     *     response="200",
     *     description="Query successful",
     *     @OA\JsonContent(
     *       type="array",
     *       @OA\Items(ref="#/components/schemas/BarePublicUser")
     *     )
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function list(Request $request, User $user): JsonResponse
    {
        if (!perm(Role::Staff)) {
            abort(401);
        }

        $fetch_role = Role::User;
        $roles = [$fetch_role];
        $dev_role_label = SettingsHelper::get('dev_role_label');
        if ($dev_role_label === $fetch_role) {
            $roles[] = Role::Developer;
        }
        $users = User::whereIn('role', $roles)->orderBy('name')->get(['id', 'name']);

        return response()->camelJson($users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'role' => $fetch_role,
        ]));
    }

    /**
     * @OA\Post(
     *   path="/users/me/password",
     *   operationId="PostUsersMePassword",
     *   description="Sets a new password for the currently signed in user. Requires staff permission. If a password is already set, the current password must be provided for verification. On success, all existing sessions (access tokens) of the user are deleted.",
     *   tags={"users"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"newPassword"},
     *     @OA\Property(property="currentPassword", type="string", description="The user's current password, required if a password is already set"),
     *     @OA\Property(property="newPassword", type="string", minLength=8, maxLength=300, description="The new password to set")
     *   )),
     *   @OA\Response(response="200", description="Password successfully changed", @OA\JsonContent(type="object", required={"message"}, @OA\Property(property="message", type="string"))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permission (staff required)", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Invalid new password, or the current password is missing or incorrect", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function setPassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->password !== null) {
            $current = $request->input('currentPassword');
            if (!is_string($current) || $current === '') {
                throw ValidationException::withMessages(['currentPassword' => 'The current password is required']);
            }
            if (!Hash::check($current, $user->password)) {
                throw ValidationException::withMessages(['currentPassword' => 'The current password is incorrect']);
            }
        }

        $valid = Validator::make($request->only('newPassword'), [
            'newPassword' => ['required', 'string', 'min:8', 'max:300', new Pwned],
        ], [
            'newPassword.required' => 'The new password is required',
            'newPassword.min' => 'The new password must be between 8 and 300 characters long',
            'newPassword.max' => 'The new password must be between 8 and 300 characters long',
        ])->validate();

        DB::transaction(function () use ($user, $valid) {
            $user->forceFill(['password' => Hash::make($valid['newPassword'])])->save();
            $user->tokens()->delete();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table'))->where('user_id', $user->id)->delete();
            }
        });

        return response()->json(['message' => 'Your new password has been set successfully. As a security precaution your existing sessions have been deleted, so you will need to log in again.']);
    }

    /**
     * @OA\Post(
     *   path="/users/signout",
     *   description="Shortcut for calling the token DELETE endpoint with the current token",
     *   tags={"authentication","users"},
     *   security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *   @OA\Response(
     *     response="204",
     *     description="Signout successful"
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     *
     * @param  Request  $request
     * @return Response
     */
    public function signout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken|TransientToken|null */
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logoutCurrentDevice();
        }

        return response()->noContent();
    }

    /**
     * @OA\Schema(
     *   schema="Token",
     *   type="object",
     *   required={
     *     "id",
     *     "name",
     *     "lastUsedAt",
     *     "createdAt"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="id",
     *     type="integer",
     *     minimum=1,
     *     example=1,
     *   ),
     *   @OA\Property(
     *     property="name",
     *     type="string",
     *     description="Name of the token, either generated (from OS and browser version) or user-supplied if renamed",
     *   ),
     *   @OA\Property(
     *     property="lastUsedAt",
     *     nullable=true,
     *     description="Null for a token that was never used",
     *     oneOf={@OA\Schema(ref="#/components/schemas/IsoStandardDate")}
     *   ),
     *   @OA\Property(
     *     property="createdAt",
     *     ref="#/components/schemas/IsoStandardDate"
     *   ),
     * )
     *
     * @OA\Get(
     *   path="/users/tokens",
     *   description="Returns a list of access tokens that belong to the current user",
     *   tags={"authentication","users"},
     *   security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *   @OA\Response(
     *     response="200",
     *     description="Success",
     *     @OA\JsonContent(
     *       required={
     *         "currentTokenId",
     *         "tokens",
     *       },
     *       additionalProperties=false,
     *       @OA\Property(
     *         property="currentTokenId",
     *         description="ID of the token used to make this request. Will be null if the request is authenticated through CookieAuth",
     *         type="integer",
     *         minimum=1,
     *         example=1,
     *         nullable=true,
     *       ),
     *       @OA\Property(
     *         property="tokens",
     *         description="A list of tokens that belong to the user",
     *         type="array",
     *         minItems=1,
     *         @OA\Items(ref="#/components/schemas/Token")
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     * @param  Request  $request
     * @return JsonResponse
     */
    public function tokens(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken|TransientToken $current_token */
        $current_token = $user->currentAccessToken();

        return response()->camelJson([
            'current_token_id' => $current_token->id ?? null,
            'tokens' => $user->tokens->map(function (PersonalAccessToken $t) {
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'lastUsedAt' => Date::maybeToString($t->last_used_at),
                    'createdAt' => Date::maybeToString($t->created_at),
                ];
            })
        ]);
    }

    /**
     * @OA\Schema(
     *   schema="BrowserSession",
     *   type="object",
     *   description="A browser session of the current user (cookie authentication)",
     *   required={"id", "device", "userAgent", "ip", "lastActiveAt", "createdAt", "current"},
     *   additionalProperties=false,
     *   @OA\Property(property="id", type="string", description="Opaque identifier to pass to DELETE /users/sessions/{id}. Not the session id itself", example="9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08"),
     *   @OA\Property(property="device", type="string", description="Browser and operating system the session was started from", example="Firefox on Linux"),
     *   @OA\Property(property="userAgent", type="string", nullable=true, description="The raw User-Agent header the session was started with"),
     *   @OA\Property(property="ip", type="string", nullable=true, description="IP address the session was last used from"),
     *   @OA\Property(property="lastActiveAt", type="string", format="date-time"),
     *   @OA\Property(property="createdAt", type="string", format="date-time", nullable=true),
     *   @OA\Property(property="current", type="boolean", description="Whether this is the session that made the request")
     * )
     * @OA\Get(
     *   path="/users/sessions",
     *   operationId="GetUsersSessions",
     *   description="Browser sessions (cookie authentication) of the current user, most recently active first. Access tokens are listed by `GET /users/tokens`.",
     *   tags={"authentication","users"},
     *   security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *   @OA\Response(response="200", description="Success", @OA\JsonContent(type="object", required={"sessions"}, additionalProperties=false,
     *     @OA\Property(property="sessions", type="array", @OA\Items(ref="#/components/schemas/BrowserSession"))
     *   )),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function sessions(Request $request): JsonResponse
    {
        $current = $request->hasSession() ? $request->session()->getId() : null;
        $sessions = DB::table(config('session.table'))->where('user_id', $request->user()->id)->orderByDesc('last_activity')->get()
            ->map(fn($row) => [
                'id' => hash('sha256', $row->id),
                'device' => Core::describeUserAgent($row->user_agent),
                'userAgent' => $row->user_agent,
                'ip' => $row->ip_address,
                'lastActiveAt' => Date::createFromTimestamp($row->last_activity)->toIso8601String(),
                'createdAt' => $row->created_at === null ? null : Date::parse($row->created_at)->toIso8601String(),
                'current' => $current !== null && hash_equals($row->id, $current),
            ])->values();

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * @OA\Delete(
     *   path="/users/sessions/{id}",
     *   operationId="DeleteUsersSessionsId",
     *   description="Ends a browser session of the current user, which signs that browser out. Use `POST /users/signout` for the session making the request.",
     *   tags={"authentication","users"},
     *   security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(type="string"), description="The `id` from `GET /users/sessions`"),
     *   @OA\Response(response="204", description="The session was ended"),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="No such session for this user", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function deleteSession(Request $request, string $id): Response
    {
        $table = config('session.table');
        $row = DB::table($table)->where('user_id', $request->user()->id)->get(['id'])->first(fn($row) => hash_equals(hash('sha256', $row->id), $id));
        if ($row === null) {
            abort(404, 'Session not found');
        }
        DB::table($table)->where('id', $row->id)->delete();

        return response()->noContent();
    }

    /**
     * @OA\Delete(
     *   path="/users/tokens/{id}",
     *   description="Deletes an access token that belongs to the current user",
     *   tags={"authentication"},
     *   security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *   @OA\Parameter(
     *     in="path",
     *     name="id",
     *     required=true,
     *     @OA\Schema(
     *       type="integer",
     *       minimum=1,
     *       example=1
     *     ),
     *     description="The ID of the token to delete"
     *   ),
     *   @OA\Response(
     *     response="204",
     *     description="Success"
     *   ),
     *   @OA\Response(
     *     response="404",
     *     description="Token not found",
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     * @param  int  $token_id
     * @param  Request  $request
     * @return Response
     */
    public function deleteToken(int $token_id, Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken $token */
        $token = $user->tokens->where('id', $token_id)->first();
        if ($token === null) {
            abort(404);
        }

        $token->delete();

        return response()->noContent();
    }

    /**
     * @OA\Put(
     *   path="/users/{id}/role",
     *   operationId="PutUsersIdRole",
     *   description="Change the role of a user in the same or a lower level group than yours. Changing a developer's role changes the label that is shown for developers instead. Requires staff",
     *   tags={"users"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"value"}, @OA\Property(property="value", ref="#/components/schemas/DatabaseRole"))),
     *   @OA\Response(response="200", description="The user already has this role", @OA\JsonContent(type="object", required={"alreadyIn"}, @OA\Property(property="alreadyIn", type="boolean"))),
     *   @OA\Response(response="204", description="Changed"),
     *   @OA\Response(response="403", description="Not allowed to change this user's role", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function setRole(Request $request, int $id)
    {
        $target = User::findOrFail($id);
        $actor = $request->user();
        if ($target->id === $actor->id) {
            throw new AuthorizationException('You cannot modify your own group');
        }
        if (!perm($target->role, $actor->role)) {
            throw new AuthorizationException('You can only modify the group of users who are in the same or a lower-level group than you');
        }

        $valid = Validator::make($request->all(), ['value' => ['required', new Enum(Role::class)]], [
            'value.required' => 'The new group is not specified',
            'value.Illuminate\Validation\Rules\Enum' => 'The specified group does not exist',
        ])->validate();
        $new_role = Role::from($valid['value']);

        if ($target->role === $new_role) {
            return response()->json(['alreadyIn' => true]);
        }

        $target->updateRole($new_role);

        return response()->noContent();
    }
}
