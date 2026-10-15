<?php

namespace App\Utils;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * E-mail addresses of users are stored encrypted with the app key (Laravel's own encrypter, a different ciphertext every time) next to `email_hash`, a keyed
 * hash of the lowercase address that is what lookups and the uniqueness of addresses go by, since the ciphertext cannot be searched. Nothing outside
 * `User` should need to know: the model's `email` attribute reads and writes plain addresses, `User::whereEmail()` finds by address
 */
class EmailVault
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** What the database holds for an address: `null` stays `null` and so does an empty string */
    public static function encrypt(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return Crypt::encryptString(trim($email));
    }

    /** The keyed hash lookups go by, `null` for no address */
    public static function hash(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return hash_hmac('sha256', self::normalize($email), self::hashKey());
    }

    /** Whether a stored value is a payload of the encrypter (anything else is an address that has not been encrypted yet, or never will be: Winterchilla writes plain ones) */
    public static function isEncrypted(?string $stored): bool
    {
        if ($stored === null || $stored === '' || !preg_match('/^[A-Za-z0-9+\/]+=*$/', $stored)) {
            return false;
        }
        $payload = json_decode((string) base64_decode($stored, true), true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }

    /**
     * The address a stored value stands for. Values that are not encrypted are what they are (the table is shared with the old site, which writes
     * plain addresses), a value that is but cannot be decrypted (the wrong key) is none, never the ciphertext
     */
    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        if (!self::isEncrypted($stored)) {
            return $stored;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::error('An e-mail address could not be decrypted, is the app key the one it was encrypted with?');

            return null;
        }
    }

    private static function hashKey(): string
    {
        $key = config('app.email_hash_key') ?: config('app.key');
        if (is_string($key) && str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        return (string) $key;
    }
}
