<?php

namespace App\Utils;

use App\Models\DeviantartUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Keeps the DeviantArt tokens of club members alive and signs a user out of everything when DeviantArt no longer accepts theirs, like
 * Winterchilla did on every request (its session was a DeviantArt session). A refresh token works only once, so while Winterchilla still
 * uses the same accounts this stays off (`DEVIANTART_TOKEN_SYNC`): refreshing here would sign people out there.
 */
class DeviantArtTokens
{
    public const TOKEN_URL = 'https://www.deviantart.com/oauth2/token';

    public const OK = 'ok';

    /** DeviantArt refused the refresh token (revoked, expired, already used) or there is none */
    public const REVOKED = 'revoked';

    /** DeviantArt could not be reached or answered with an error of its own, nothing is known about the token */
    public const UNAVAILABLE = 'unavailable';

    public static function enabled(): bool
    {
        return (bool) config('services.deviantart.token_sync');
    }

    /**
     * Trades the refresh token for a new pair of tokens and stores them
     *
     * @return self::OK|self::REVOKED|self::UNAVAILABLE
     */
    public static function refresh(DeviantartUser $record): string
    {
        if (empty($record->refresh)) {
            return self::REVOKED;
        }

        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $record->refresh,
                'client_id' => config('services.deviantart.client_id'),
                'client_secret' => config('services.deviantart.client_secret'),
            ]);
        } catch (ConnectionException) {
            return self::UNAVAILABLE;
        }

        if ($response->serverError() || $response->status() === 429) {
            return self::UNAVAILABLE;
        }
        $body = $response->json();
        if (!$response->successful() || empty($body['access_token']) || empty($body['refresh_token'])) {
            return self::REVOKED;
        }

        $record->forceFill([
            'access' => $body['access_token'],
            'refresh' => $body['refresh_token'],
            'access_expires' => now()->addSeconds((int) ($body['expires_in'] ?? 3600)),
        ])->save();

        return self::OK;
    }

    /**
     * Forgets the dead tokens and ends every session and access token of the user, only a DeviantArt sign-in brings them back
     */
    public static function signOut(DeviantartUser $record): void
    {
        DB::transaction(function () use ($record) {
            $record->forceFill(['access' => null, 'refresh' => null, 'access_expires' => null])->save();
            $user = $record->user()->first();
            if ($user === null) {
                return;
            }
            $user->tokens()->delete();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table'))->where('user_id', $user->id)->delete();
            }
        });
    }

    /**
     * Makes sure the user's DeviantArt link holds working tokens (used before a sign-in by e-mail)
     *
     * @return self::OK|self::REVOKED|self::UNAVAILABLE
     */
    public static function check(DeviantartUser $record): string
    {
        if (!empty($record->access) && $record->access_expires !== null && $record->access_expires->isFuture()) {
            return self::OK;
        }
        $result = self::refresh($record);
        if ($result === self::REVOKED) {
            self::signOut($record);
        }

        return $result;
    }
}
