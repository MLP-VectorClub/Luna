<?php

namespace App\Http\Controllers;

use App\Models\DiscordMember;
use App\Utils\AccountHelper;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Annotations as OA;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DiscordController extends Controller
{
    private const API = 'https://discord.com/api/v10';

    /**
     * @OA\Post(
     *   path="/users/{user_id}/discord/sync",
     *   operationId="PostUsersUserIdDiscordSync",
     *   description="Refresh the stored Discord account information (name, avatar, server membership) of a user. The user themselves or staff. At most once every 5 minutes.",
     *   tags={"discord"},
     *   @OA\Parameter(in="path", name="user_id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="204", description="Synced"),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Not the user or staff", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="User not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="No Discord account is bound to the user, or it is not linked", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="429", description="Synced less than 5 minutes ago", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function sync(Request $request, int $user_id): Response
    {
        $member = $this->target($request, $user_id);
        if ($member->access === null) {
            throw new HttpException(409, 'The Discord account must be linked before syncing');
        }
        if ($member->last_synced !== null && $member->last_synced->getTimestamp() + DiscordMember::SYNC_COOLDOWN > time()) {
            throw new HttpException(429, "The account information was last updated {$member->last_synced->diffForHumans()}, please wait at least 5 minutes before syncing again.");
        }

        $this->refreshToken($member);
        $owner = Http::withToken($member->access)->get(self::API.'/users/@me');
        if ($owner->status() === 401) {
            $member->delete();
            throw new HttpException(409, 'The site is no longer authorized to access the Discord account data, the link has been removed.');
        }
        if (!$owner->successful()) {
            throw new HttpException(502, 'Discord could not be reached, please try again later.');
        }
        $member->username = $owner->json('username');
        $member->display_name = $owner->json('global_name');
        $member->discriminator = (int) $owner->json('discriminator', 0);
        $member->avatar_hash = $owner->json('avatar');
        $member->last_synced = now();
        $this->checkServerMembership($member);
        $member->save();

        return response()->noContent();
    }

    /**
     * @OA\Post(
     *   path="/users/{user_id}/discord",
     *   operationId="PostUsersUserIdDiscord",
     *   description="Link a Discord account to the signed in user with the authorization code Discord sent back (the redirect that started it is `GET /users/oauth/signin/discord`, the same one sign-in uses). Only for the user themselves. A Discord account that another user has linked cannot be taken over, and a previously linked other account is replaced.",
     *   tags={"discord"},
     *   @OA\Parameter(in="path", name="user_id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/OauthCode")),
     *   @OA\Response(response="204", description="Linked"),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Not the user", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="The Discord account belongs to another user, or the user has linked one already", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="No code, or Discord refused it", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse")),
     *   @OA\Response(response="502", description="Discord could not be reached", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function link(Request $request, int $user_id): Response
    {
        $user = $request->user();
        if ($user->id !== $user_id) {
            throw new AuthorizationException();
        }
        $request->validate(['code' => ['required', 'string']]);
        $current = $user->discordMember()->first();
        if ($current !== null && $current->access !== null) {
            throw new HttpException(409, 'A Discord account is already linked, unlink it first');
        }

        $data = AccountHelper::socialUser($request, 'discord');
        DB::transaction(function () use ($data, $user) {
            $member = DiscordMember::find($data->getId()) ?? (new DiscordMember())->forceFill(['id' => $data->getId()]);
            if ($member->user_id !== null && (int) $member->user_id !== $user->id) {
                throw new HttpException(409, 'This Discord account is linked to another user');
            }
            // A bound but unlinked record of this user for another Discord account is replaced by the one just linked
            DiscordMember::where('user_id', $user->id)->where('id', '!=', $data->getId())->delete();

            $raw = $data->getRaw();
            $member->user_id = $user->id;
            $member->username = $raw['username'] ?? $data->getNickname();
            $member->display_name = $raw['global_name'] ?? null;
            $member->discriminator = (int) ($raw['discriminator'] ?? 0);
            $member->avatar_hash = $raw['avatar'] ?? null;
            $member->last_synced = now();
            foreach (AccountHelper::tokenResponseToModelData($data) as $k => $v) {
                $member->setAttribute($k, $v);
            }
            $this->checkServerMembership($member);
            $member->save();
        });

        return response()->noContent();
    }

    /**
     * @OA\Delete(
     *   path="/users/{user_id}/discord",
     *   operationId="DeleteUsersUserIdDiscord",
     *   description="Revoke the site's access to a user's Discord account and forget the account. The user themselves or staff.",
     *   tags={"discord"},
     *   @OA\Parameter(in="path", name="user_id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="Unlinked", @OA\JsonContent(type="object", required={"message"}, @OA\Property(property="message", type="string"))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Not the user or staff", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="User not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="No Discord account is bound to the user", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="502", description="Discord refused to revoke the access", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function unlink(Request $request, int $user_id): JsonResponse
    {
        $member = $this->target($request, $user_id);
        $same_user = $request->user()->id === $user_id;

        if ($member->access !== null && !config('services.discord.skip_revoke')) {
            $response = Http::asForm()->post(self::API.'/oauth2/token/revoke', [
                'token' => $member->refresh,
                'token_type_hint' => 'refresh_token',
                'client_id' => config('services.discord.client_id'),
                'client_secret' => config('services.discord.client_secret'),
            ]);
            // An unknown client means the grant is already gone
            if (!$response->successful() && $response->json('error') !== 'invalid_client') {
                Log::error("Revoking Discord access failed for user $user_id", ['status' => $response->status(), 'body' => $response->body()]);
                throw new HttpException(502, 'Revoking access failed, please let us know so we can look into the issue.');
            }
        }
        $member->delete();

        $your = $same_user ? 'Your' : 'This';

        return response()->json(['message' => "$your Discord account was successfully unlinked.".($same_user
            ? ' If you want to verify it yourself, check your Authorized Apps in your settings.' : '')]);
    }

    private function target(Request $request, int $user_id): DiscordMember
    {
        $user = User::findOrFail($user_id);
        if ($user->id !== $request->user()->id && !$request->user()->isStaff()) {
            throw new AuthorizationException();
        }
        $member = $user->discordMember()->first();
        if ($member === null) {
            throw new HttpException(409, 'You must be bound to a Discord user to perform this action');
        }

        return $member;
    }

    private function refreshToken(DiscordMember $member): void
    {
        if ($member->expires === null || $member->expires->getTimestamp() > time() + 10) {
            return;
        }
        $response = Http::asForm()->post(self::API.'/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $member->refresh,
            'client_id' => config('services.discord.client_id'),
            'client_secret' => config('services.discord.client_secret'),
        ]);
        if ($response->json('error') === 'invalid_grant') {
            $member->delete();
            throw new HttpException(409, 'The Discord account link got severed, you will need to re-link your account.');
        }
        if (!$response->successful()) {
            throw new HttpException(502, 'Discord could not be reached, please try again later.');
        }
        $member->access = $response->json('access_token');
        $member->refresh = $response->json('refresh_token');
        $member->expires = now()->addSeconds((int) $response->json('expires_in'));
        $member->scope = $response->json('scope');
    }

    private function checkServerMembership(DiscordMember $member): void
    {
        $guild = config('services.discord.guild_id');
        $response = Http::withHeaders(['Authorization' => 'Bot '.config('services.discord.bot_token')])
            ->get(self::API."/guilds/$guild/members/{$member->id}");
        if ($response->status() === 404) {
            $member->nick = null;
            $member->joined_at = null;
        } elseif ($response->successful()) {
            $member->nick = $response->json('nick');
            $member->joined_at = $response->json('joined_at');
        } else {
            throw new HttpException(502, 'Discord could not be reached, please try again later.');
        }
    }
}
