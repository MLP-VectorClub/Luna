<?php

namespace Tests\Support;

/**
 * An authenticator for tests: holds a P-256 key and answers the way a browser's `PublicKeyCredential.toJSON()` does for a registration
 * (`navigator.credentials.create`) and a sign-in (`navigator.credentials.get`), with a "none" attestation
 */
class FakeAuthenticator
{
    public string $credentialId;
    private \OpenSSLAsymmetricKey $key;
    public int $counter = 0;

    public function __construct(public string $origin, public string $rpId)
    {
        $this->credentialId = random_bytes(32);
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        return base64_decode(strtr($text, '-_', '+/'));
    }

    // Just enough CBOR for an attestation object and a COSE key
    private static function head(int $major, int $n): string
    {
        return match (true) {
            $n < 24 => chr(($major << 5) | $n),
            $n < 256 => chr(($major << 5) | 24) . chr($n),
            default => chr(($major << 5) | 25) . pack('n', $n),
        };
    }

    private static function cbor(mixed $value): string
    {
        return match (true) {
            is_int($value) && $value >= 0 => self::head(0, $value),
            is_int($value) => self::head(1, -1 - $value),
            is_array($value) && ($value['__bytes'] ?? null) !== null => self::head(2, strlen($value['__bytes'])) . $value['__bytes'],
            is_array($value) => self::head(5, count($value)) . implode('', array_map(
                fn($k, $v) => self::cbor($k) . self::cbor($v),
                array_keys($value),
                $value
            )),
            default => self::head(3, strlen($value)) . $value,
        };
    }

    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key)['ec'];

        // OpenSSL drops leading zero bytes of the coordinates (about one key in 128), a P-256 coordinate is always 32 bytes
        $x = str_pad($details['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['y'], 32, "\0", STR_PAD_LEFT);

        return self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => ['__bytes' => $x], -3 => ['__bytes' => $y]]);
    }

    private function clientData(string $type, string $challenge): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $this->origin, 'crossOrigin' => false]);
    }

    /** @param array $options the `options` the API handed out */
    public function register(array $options): array
    {
        $authData = hash('sha256', $this->rpId, true) . chr(0x45) . pack('N', 0) . str_repeat("\0", 16)
            . pack('n', strlen($this->credentialId)) . $this->credentialId . $this->coseKey();
        $clientData = $this->clientData('webauthn.create', $options['challenge']);

        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'attestationObject' => self::b64(self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => ['__bytes' => $authData]])),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => (object)[],
        ];
    }

    public function sign(array $options, ?string $userHandle, ?int $counter = null): array
    {
        $this->counter = $counter ?? $this->counter + 1;
        $authData = hash('sha256', $this->rpId, true) . chr(0x05) . pack('N', $this->counter);
        $clientData = $this->clientData('webauthn.get', $options['challenge']);
        openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'authenticatorData' => self::b64($authData),
                'signature' => self::b64($signature),
                'userHandle' => $userHandle === null ? null : self::b64($userHandle),
            ],
            'clientExtensionResults' => (object)[],
        ];
    }
}
