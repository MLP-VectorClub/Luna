<?php

namespace App\Http\Controllers;

use App\Models\Passkey;
use App\Models\User;
use App\Utils\IdentityCheck;
use App\Utils\Passkeys;
use App\Utils\SignedChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * The passkeys of the signed in account. Adding one is two steps (options for `navigator.credentials.create`, then what the browser made), asks for
 * the current password at the first and leaves nothing on the server in between
 */
class PasskeysController extends Controller
{
    private const PURPOSE = 'passkey-register';
    private const MAX_PER_USER = 20;

    #[OA\Schema(
        schema: 'Passkey',
        type: 'object',
        required: ['id', 'name', 'createdAt', 'lastUsedAt'],
        additionalProperties: false,
        properties: [
            new OA\Property(property: 'id', type: 'integer', minimum: 1),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
            new OA\Property(property: 'lastUsedAt', type: 'string', format: 'date-time', nullable: true),
        ],
    )]
    private static function map(Passkey $passkey): array
    {
        return [
            'id' => $passkey->id,
            'name' => $passkey->name,
            'createdAt' => $passkey->created_at?->toIso8601String(),
            'lastUsedAt' => $passkey->last_used_at?->toIso8601String(),
        ];
    }

    private function own(Request $request, int $id): Passkey
    {
        return Passkey::where('user_id', $request->user()->id)->findOrFail($id);
    }

    #[OA\Get(
        path: '/users/me/passkeys',
        description: 'The passkeys of the account',
        tags: ['account'],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Passkeys, oldest first',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Passkey')),
            ),
            new OA\Response(
                response: '401',
                description: 'Not signed in',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Passkey::where('user_id', $request->user()->id)->orderBy('id')->get()->map(fn(Passkey $p) => self::map($p))->all()
        );
    }

    #[OA\Post(
        path: '/users/me/passkeys/options',
        description: 'Options for adding a passkey (`navigator.credentials.create`), good for five minutes',
        tags: ['account'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                additionalProperties: false,
                properties: [
                    new OA\Property(property: 'currentPassword', type: 'string'),
                    new OA\Property(property: 'code', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Options for the browser',
                content: new OA\JsonContent(ref: '#/components/schemas/PasskeyOptionsResponse'),
            ),
            new OA\Response(
                response: '409',
                description: 'The account has as many passkeys as it may',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '422',
                description: 'The password (or code) is missing or wrong',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function options(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        IdentityCheck::confirm($request, $user);

        $user->load('passkeys');
        if ($user->passkeys->count() >= self::MAX_PER_USER) {
            return response()->json(['message' => 'There are as many passkeys as an account may have, remove one first'], 409);
        }

        $options = Passkeys::creationOptions($user);

        return response()->json([
            'options' => Passkeys::toArray($options),
            'challenge' => SignedChallenge::issue(self::PURPOSE, ['options' => Passkeys::dump($options), 'user' => $user->id], 300),
        ]);
    }

    #[OA\Post(
        path: '/users/me/passkeys',
        description: 'Adds the passkey the browser made for the options',
        tags: ['account'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['challenge', 'credential'],
                additionalProperties: false,
                properties: [
                    new OA\Property(property: 'challenge', type: 'string'),
                    new OA\Property(
                        property: 'credential',
                        type: 'object',
                        additionalProperties: true,
                        description: 'What `navigator.credentials.create` returned, as JSON',
                    ),
                    new OA\Property(
                        property: 'name',
                        type: 'string',
                        maxLength: 64,
                        description: 'What the passkey is called in the list, `Passkey` when left out',
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Added',
                content: new OA\JsonContent(ref: '#/components/schemas/Passkey'),
            ),
            new OA\Response(
                response: '422',
                description: 'The options expired or the browser\'s answer did not check out',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function register(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $input = $request->validate([
            'challenge' => 'required|string',
            'credential' => 'required|array',
            'name' => 'sometimes|nullable|string|max:64',
        ]);

        $challenge = SignedChallenge::consume(self::PURPOSE, $input['challenge']);
        if ($challenge === null || ($challenge['data']['user'] ?? null) !== $user->id) {
            throw ValidationException::withMessages(['challenge' => 'This has expired, please start over']);
        }

        $credential = Passkeys::loadCredential(json_encode($input['credential']));
        if ($credential === null) {
            throw ValidationException::withMessages(['credential' => 'That is not a passkey']);
        }

        try {
            $record = Passkeys::verifyRegistration($credential, Passkeys::loadCreationOptions($challenge['data']['options']));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['credential' => 'That passkey was not accepted']);
        }

        if (Passkey::where('credential_id', Passkeys::credentialId($record->publicKeyCredentialId))->exists()) {
            throw ValidationException::withMessages(['credential' => 'That passkey is saved already']);
        }

        $name = trim((string)($input['name'] ?? '')) ?: 'Passkey';
        $passkey = Passkeys::save($user, $name, $record);

        return response()->json(self::map($passkey->refresh()), 201);
    }

    #[OA\Patch(
        path: '/users/me/passkeys/{id}',
        description: 'Renames a passkey',
        tags: ['account'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['name'],
                additionalProperties: false,
                properties: [new OA\Property(property: 'name', type: 'string', maxLength: 64)],
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Renamed',
                content: new OA\JsonContent(ref: '#/components/schemas/Passkey'),
            ),
            new OA\Response(
                response: '404',
                description: 'Not a passkey of the account',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid name',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function rename(Request $request, int $id): JsonResponse
    {
        $passkey = $this->own($request, $id);
        $input = $request->validate(['name' => 'required|string|max:64']);
        $passkey->update(['name' => trim($input['name'])]);

        return response()->json(self::map($passkey));
    }

    #[OA\Delete(
        path: '/users/me/passkeys/{id}',
        description: 'Removes a passkey. Every other session and token of the account ends',
        tags: ['account'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                additionalProperties: false,
                properties: [
                    new OA\Property(property: 'currentPassword', type: 'string'),
                    new OA\Property(property: 'code', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '204', description: 'Removed'),
            new OA\Response(
                response: '404',
                description: 'Not a passkey of the account',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '422',
                description: 'The password (or code) is missing or wrong',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function destroy(Request $request, int $id): Response
    {
        $passkey = $this->own($request, $id);
        IdentityCheck::confirm($request, $request->user());

        $passkey->delete();
        IdentityCheck::endOtherSessions($request, $request->user());

        return response()->noContent();
    }
}
