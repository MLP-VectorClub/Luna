<?php

namespace App\Utils;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The link to the websocket server (Muffins, which also served Winterchilla). Browsers prove who they are to it with a one time token that Luna hands out
 * ({@see issueToken()}) and the server checks with Luna ({@see userForToken()}); Luna tells it when a user has news ({@see notifyUser()}).
 * Nothing here is needed for the site to work, notifications are also fetched every minute.
 */
class WebsocketServer
{
    private const TOKEN_LIFETIME_SECONDS = 120;

    public static function enabled(): bool
    {
        return config('services.websocket.host') !== null && config('services.websocket.key') !== null;
    }

    /**
     * A token for one connection of the user's browser. It is only kept as a hash, works once and only for a couple of minutes (the browser asks for a
     * new one for every connection, also when it reconnects)
     */
    public static function issueToken(User $user): string
    {
        $token = Str::random(48);
        Cache::put(self::cacheKey($token), $user->id, self::TOKEN_LIFETIME_SECONDS);

        return $token;
    }

    /**
     * Spends the token. Null when it is unknown, used or expired
     */
    public static function userForToken(string $token): ?User
    {
        $user_id = Cache::pull(self::cacheKey($token));

        return $user_id === null ? null : User::find($user_id);
    }

    /**
     * Has the server tell the user's open browsers to look at their notifications again. A server that does not answer is not a reason to fail whatever
     * created the notification
     */
    public static function notifyUser(int $user_id): void
    {
        $url = config('services.websocket.url');
        $key = config('services.websocket.key');
        if ($url === null || $key === null) {
            return;
        }

        try {
            Http::timeout(2)->withToken($key)->asJson()->post(rtrim($url, '/').'/notify', ['user' => (string) $user_id]);
        } catch (ConnectionException $e) {
            Log::info('The websocket server could not be told about a notification: '.$e->getMessage());
        }
    }

    private static function cacheKey(string $token): string
    {
        return 'ws-token:'.hash('sha256', $token);
    }
}
