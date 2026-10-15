<?php

namespace App\Utils;

use App\Models\Passkey;
use App\Models\User;
use Cose\Algorithms;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkeys (WebAuthn): the options handed to the browser and the checks of what it answers. The options travel as JSON in an encrypted, expiring
 * `SignedChallenge`, so the server keeps nothing between the two requests
 */
class Passkeys
{
    private static ?SerializerInterface $serializer = null;

    public static function serializer(): SerializerInterface
    {
        return self::$serializer ??= (new WebauthnSerializerFactory(
            AttestationStatementSupportManager::create([new NoneAttestationStatementSupport()])
        ))->create();
    }

    private static function factory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins(config('webauthn.origins'));
        // Plain http is only for the development host
        $factory->setSecuredRelyingPartyId(['localhost']);

        return $factory;
    }

    public static function rp(): PublicKeyCredentialRpEntity
    {
        return PublicKeyCredentialRpEntity::create(config('webauthn.rp_name'), config('webauthn.rp_id'));
    }

    /** What the browser gets: JSON-ready, without the null values it would not understand */
    public static function toArray(object $options): array
    {
        return json_decode(self::serializer()->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR,
        ]), true, 32, JSON_THROW_ON_ERROR);
    }

    /** Options for signing in: no credential is named, the authenticator offers the passkeys it holds for the site (discoverable credentials) */
    public static function requestOptions(): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            config('webauthn.rp_id'),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            60000
        );
    }

    /** Options for a second factor of a known user: only their own passkeys are named */
    public static function requestOptionsFor(User $user): PublicKeyCredentialRequestOptions
    {
        $allowed = $user->passkeys->map(fn(Passkey $p) => self::record($p)->getPublicKeyCredentialDescriptor())->all();

        return PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            config('webauthn.rp_id'),
            $allowed,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            60000
        );
    }

    public static function creationOptions(User $user): PublicKeyCredentialCreationOptions
    {
        $exclude = $user->passkeys->map(fn(Passkey $p) => PublicKeyCredentialDescriptor::create(
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            self::record($p)->publicKeyCredentialId
        ))->all();

        return PublicKeyCredentialCreationOptions::create(
            self::rp(),
            PublicKeyCredentialUserEntity::create($user->name, self::userHandle($user), $user->name),
            random_bytes(32),
            [
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
            ],
            AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclude,
            60000
        );
    }

    public static function userHandle(User $user): string
    {
        return (string)$user->id;
    }

    public static function dump(object $options): string
    {
        return self::serializer()->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR,
        ]);
    }

    public static function loadRequestOptions(string $json): PublicKeyCredentialRequestOptions
    {
        return self::serializer()->deserialize($json, PublicKeyCredentialRequestOptions::class, 'json');
    }

    public static function loadCreationOptions(string $json): PublicKeyCredentialCreationOptions
    {
        return self::serializer()->deserialize($json, PublicKeyCredentialCreationOptions::class, 'json');
    }

    /** The credential the browser sends back, null when it is not a well formed one */
    public static function loadCredential(string $json): ?PublicKeyCredential
    {
        try {
            $credential = self::serializer()->deserialize($json, PublicKeyCredential::class, 'json');
        } catch (\Throwable) {
            return null;
        }

        return $credential;
    }

    public static function record(Passkey $passkey): CredentialRecord
    {
        return self::serializer()->deserialize($passkey->record, CredentialRecord::class, 'json');
    }

    private static function dumpRecord(CredentialRecord $record): string
    {
        return self::serializer()->serialize($record, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR,
        ]);
    }

    public static function credentialId(string $rawId): string
    {
        return rtrim(strtr(base64_encode($rawId), '+/', '-_'), '=');
    }

    /** Checks a new passkey and returns the record to keep. Throws when the browser's answer does not hold up */
    public static function verifyRegistration(PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): CredentialRecord
    {
        $response = $credential->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw new \InvalidArgumentException('Not an attestation response');
        }

        return AuthenticatorAttestationResponseValidator::create(self::factory()->creationCeremony())
            ->check($response, $options, config('webauthn.rp_id'));
    }

    /** Checks a sign-in with a passkey, returns the record with its counter moved on. Throws when the answer does not hold up */
    public static function verifyAssertion(
        Passkey $passkey,
        PublicKeyCredential $credential,
        PublicKeyCredentialRequestOptions $options
    ): CredentialRecord {
        $response = $credential->response;
        if (!$response instanceof AuthenticatorAssertionResponse) {
            throw new \InvalidArgumentException('Not an assertion response');
        }

        return AuthenticatorAssertionResponseValidator::create(self::factory()->requestCeremony())
            ->check(self::record($passkey), $response, $options, config('webauthn.rp_id'), $response->userHandle);
    }

    /**
     * The passkey that a credential from the browser proves to be held, null when it does not hold up (unknown, somebody else's when `$userId` is given,
     * wrong signature, counter gone backwards ...). Moves the counter on and notes the use
     */
    public static function authenticate(array $credentialData, PublicKeyCredentialRequestOptions $options, ?int $userId = null): ?Passkey
    {
        $credential = self::loadCredential(json_encode($credentialData));
        if ($credential === null) {
            return null;
        }

        $passkey = Passkey::where('credential_id', self::credentialId($credential->rawId))->first();
        if ($passkey === null || ($userId !== null && $passkey->user_id !== $userId)) {
            return null;
        }

        try {
            $record = self::verifyAssertion($passkey, $credential, $options);
        } catch (\Throwable) {
            return null;
        }
        self::touch($passkey, $record);

        return $passkey;
    }

    public static function save(User $user, string $name, CredentialRecord $record): Passkey
    {
        return Passkey::create([
            'user_id' => $user->id,
            'credential_id' => self::credentialId($record->publicKeyCredentialId),
            'record' => self::dumpRecord($record),
            'name' => $name,
        ]);
    }

    public static function touch(Passkey $passkey, CredentialRecord $record): void
    {
        $passkey->update(['record' => self::dumpRecord($record), 'last_used_at' => now()]);
    }
}
