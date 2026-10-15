<?php

namespace App\Utils;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A short lived state that the client carries between two requests of a sign-in, instead of the server keeping it in a session: guests have no session
 * (and bearer clients never had one), and a sign-in dialog that is only opened would otherwise leave a row behind. The token is encrypted with the app
 * key (so it can be neither read nor changed), expires, and is only good once: `consume` remembers its nonce until it would have expired anyway
 */
class SignedChallenge
{
    /** @param array<string, mixed> $data */
    public static function issue(string $purpose, array $data, int $ttlSeconds): string
    {
        return Crypt::encryptString(json_encode([
            'p' => $purpose,
            'n' => Str::random(24),
            'e' => now()->getTimestamp() + $ttlSeconds,
            'd' => $data,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The data of a token of this purpose, null when it is forged, of another purpose or expired. Does not use it up
     *
     * @return array{nonce: string, expires: int, data: array<string, mixed>}|null
     */
    public static function read(string $purpose, ?string $token): ?array
    {
        if (!is_string($token) || $token === '') {
            return null;
        }
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }
        if (!is_array($payload) || ($payload['p'] ?? null) !== $purpose || ($payload['e'] ?? 0) < now()->getTimestamp() || !is_string($payload['n'] ?? null)) {
            return null;
        }

        return ['nonce' => $payload['n'], 'expires' => $payload['e'], 'data' => (array)($payload['d'] ?? [])];
    }

    /** Same as `read`, and marks the token used: the next call with it gets null */
    public static function consume(string $purpose, ?string $token): ?array
    {
        $challenge = self::read($purpose, $token);
        if ($challenge === null) {
            return null;
        }
        if (!Cache::add("challenge-used:{$challenge['nonce']}", 1, max(1, $challenge['expires'] - now()->getTimestamp() + 5))) {
            return null;
        }

        return $challenge;
    }

    /** Counts a failed try of a token, true while it still has tries left (the token is spent when it does not) */
    public static function failed(array $challenge, int $maxTries): bool
    {
        $key = "challenge-tries:{$challenge['nonce']}";
        Cache::add($key, 0, max(1, $challenge['expires'] - now()->getTimestamp() + 5));
        $tries = Cache::increment($key);

        return $tries < $maxTries;
    }

    public static function spend(array $challenge): void
    {
        Cache::put("challenge-used:{$challenge['nonce']}", 1, max(1, $challenge['expires'] - now()->getTimestamp() + 5));
    }
}
