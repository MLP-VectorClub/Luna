<?php

namespace App\Http\Controllers;

use App\Enums\ApiKeyScope;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyService;
use App\Utils\IdentityCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/** The API keys of the signed in account, managed from a browser session only */
class ApiKeysController extends Controller
{
    public function __construct(private readonly ApiKeyService $keys)
    {
    }

    #[OA\Schema(
        schema: 'ApiKey',
        type: 'object',
        required: ['id', 'name', 'scopes', 'createdAt', 'lastUsedAt', 'expiresAt'],
        additionalProperties: false,
        properties: [
            new OA\Property(property: 'id', type: 'integer', minimum: 1),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string', enum: ['read', 'read:own', 'appearances:write', 'posts:write', 'user:write'])),
            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
            new OA\Property(property: 'lastUsedAt', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'expiresAt', type: 'string', format: 'date-time', nullable: true),
        ],
    )]
    private static function map(PersonalAccessToken $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'scopes' => array_values($key->abilities ?? []),
            'createdAt' => $key->created_at?->toIso8601String(),
            'lastUsedAt' => $key->last_used_at?->toIso8601String(),
            'expiresAt' => $key->expires_at?->toIso8601String(),
        ];
    }

    #[OA\Get(
        path: '/users/me/api-keys',
        description: 'The API keys of the account, oldest first. Secrets are never listed',
        tags: ['account'],
        responses: [
            new OA\Response(
                response: '200',
                description: 'API keys',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ApiKey')),
            ),
            new OA\Response(response: '401', description: 'Not signed in', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->keys->listFor($request->user())->map(fn(PersonalAccessToken $k) => self::map($k))->all());
    }

    #[OA\Post(
        path: '/users/me/api-keys',
        description: 'Creates an API key. The secret is in the response and cannot be shown again',
        tags: ['account'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['name', 'scopes'],
                additionalProperties: false,
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 60),
                    new OA\Property(property: 'scopes', type: 'array', minItems: 1, items: new OA\Items(type: 'string', enum: ['read', 'read:own', 'appearances:write', 'posts:write', 'user:write'])),
                    new OA\Property(property: 'expiresInDays', type: 'integer', enum: [30, 90, 365], nullable: true, description: 'Omit for a key that does not expire'),
                    new OA\Property(property: 'currentPassword', type: 'string'),
                    new OA\Property(property: 'code', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'The new key and its secret',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['key', 'secret'],
                    additionalProperties: false,
                    properties: [
                        new OA\Property(property: 'key', ref: '#/components/schemas/ApiKey'),
                        new OA\Property(property: 'secret', type: 'string', example: 'vck_1|abcdef'),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Not available to API keys', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: '422', description: 'Invalid input, or the password (or code) is wrong, or the account has too many keys', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiKeyScope::values())],
            'expiresInDays' => ['nullable', 'integer', Rule::in(ApiKeyService::EXPIRY_DAYS)],
        ]);
        IdentityCheck::confirm($request, $user);

        $token = $this->keys->create($user, $data['name'], array_values(array_unique($data['scopes'])), $data['expiresInDays'] ?? null);

        return response()->json(['key' => self::map($token->accessToken), 'secret' => $token->plainTextToken], 201);
    }

    #[OA\Post(
        path: '/users/me/api-keys/{id}/rotate',
        description: 'Replaces a key with a new secret that keeps its name, scopes and lifespan. The old secret stops working',
        tags: ['account'],
        parameters: [new OA\Parameter(in: 'path', name: 'id', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        requestBody: new OA\RequestBody(
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
                description: 'The new key and its secret',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['key', 'secret'],
                    additionalProperties: false,
                    properties: [
                        new OA\Property(property: 'key', ref: '#/components/schemas/ApiKey'),
                        new OA\Property(property: 'secret', type: 'string'),
                    ],
                ),
            ),
            new OA\Response(response: '404', description: 'Key not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: '422', description: 'The password (or code) is missing or wrong', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function rotate(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($this->keys->find($user, $id) === null, 404);
        IdentityCheck::confirm($request, $user);

        $token = $this->keys->rotate($user, $id);
        abort_if($token === null, 404);

        return response()->json(['key' => self::map($token->accessToken), 'secret' => $token->plainTextToken]);
    }

    #[OA\Delete(
        path: '/users/me/api-keys/{id}',
        description: 'Revokes a key, which stops working immediately',
        tags: ['account'],
        parameters: [new OA\Parameter(in: 'path', name: 'id', required: true, schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [
            new OA\Response(response: '204', description: 'Success'),
            new OA\Response(response: '404', description: 'Key not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, int $id): Response
    {
        $key = $this->keys->find($request->user(), $id);
        abort_if($key === null, 404);
        $this->keys->revoke($key);

        return response()->noContent();
    }
}
