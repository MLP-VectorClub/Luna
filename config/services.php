<?php

use App\Enums\SocialProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // The websocket server (Muffins) that tells signed in browsers about new notifications right away, see App\Utils\WebsocketServer
    'websocket' => [
        // Its public address, which browsers connect to (shown as `wsServerHost` of GET /config); empty when there is no server
        'host' => env('WS_SERVER_HOST') ?: null,
        // Where Luna reaches it from the server itself, e.g. http://127.0.0.1:3672
        'url' => env('WS_SERVER_URL') ?: null,
        // Shared secret: Luna sends it to announce notifications, Muffins sends it to check the one time tokens of browsers
        'key' => env('WS_SERVER_KEY') ?: null,
    ],

    SocialProvider::DeviantArt->value => [
        'client_id' => env('DEVIANTART_CLIENT_ID'),
        'client_secret' => env('DEVIANTART_CLIENT_SECRET'),
        // Refresh DeviantArt tokens in the background and require a DeviantArt sign-in once they stop working, see App\Utils\DeviantArtTokens
        'token_sync' => env('DEVIANTART_TOKEN_SYNC', false),
        // Proxy for the public requests to DeviantArt (short link redirects, oEmbed, club gallery check, their image hosts), e.g. socks5h://127.0.0.1:40000 for
        // Cloudflare WARP; empty for none. OEMBED_PROXY_URL is Winterchilla's name for it. Sign-in and token requests do not use it
        'proxy' => env('DEVIANTART_PROXY_URL') ?: env('OEMBED_PROXY_URL') ?: null,
        'redirect' => sprintf("%s/oauth/%s", config('app.frontend_url'), SocialProvider::Discord->value)
    ],

    SocialProvider::Discord->value => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'bot_token' => env('DISCORD_BOT_TOKEN'),
        'guild_id' => env('DISCORD_SERVER_ID'),
        // Only for the contract test server: skips the call to Discord when revoking access
        'skip_revoke' => (bool) env('DISCORD_SKIP_REVOKE', false),
        'redirect' => sprintf("%s/oauth/%s", config('app.frontend_url'), SocialProvider::Discord->value)
    ],

];
