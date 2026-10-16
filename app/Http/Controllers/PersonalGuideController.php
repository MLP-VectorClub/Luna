<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Enums\UserPrefKey;
use App\Models\Appearance;
use App\Models\PcgPointGrant;
use App\Models\PcgSlotHistory;
use App\Models\Post;
use App\Models\User;
use App\Utils\ColorGuideHelper;
use App\Utils\UserPrefHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PersonalGuideController extends Controller
{
    #[OA\Get(
        path: '/users/{id}/personal-guide/appearances',
        operationId: 'GetUsersIdPersonalGuideAppearances',
        description: 'The appearances in a user\'s Personal Color Guide. Private ones only show their name to visitors who cannot manage them',
        tags: ['personal guide'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/OneBasedId'),
            ),
            new OA\Parameter(in: 'query', name: 'page', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(
                in: 'query',
                name: 'size',
                description: 'Defaults to the user\'s items per page preference',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['appearances', 'pagination', 'canManage'],
                    properties: [
                        new OA\Property(
                            property: 'appearances',
                            type: 'array',
                            items: new OA\Items(
                                anyOf: [
                                    new OA\Schema(
                                        allOf: [
                                            new OA\Schema(ref: '#/components/schemas/Appearance'),
                                            new OA\Schema(
                                                type: 'object',
                                                required: ['private'],
                                                properties: [new OA\Property(property: 'private', type: 'boolean')],
                                            ),
                                        ],
                                    ),
                                    new OA\Schema(
                                        type: 'object',
                                        description: 'Stub of a private appearance that the visitor may not see in full',
                                        required: ['id', 'label', 'private'],
                                        properties: [
                                            new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
                                            new OA\Property(property: 'label', type: 'string'),
                                            new OA\Property(property: 'private', type: 'boolean', enum: [true]),
                                        ],
                                    ),
                                ],
                            ),
                        ),
                        new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
                        new OA\Property(
                            property: 'canManage',
                            type: 'boolean',
                            description: 'Whether the visitor may add and edit appearances in this guide',
                        ),
                    ],
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'The user hides their guide',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function appearances(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $visitor = $request->user();
        $can_manage = $visitor !== null && ($visitor->id === $user->id || $visitor->isStaff());
        if (!$can_manage && UserPrefHelper::get($user, UserPrefKey::Personal_PrivatePersonalGuide)) {
            throw new AuthorizationException("This user's Personal Color Guide is private");
        }

        $valid = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'between:1,50'],
        ], ['size.between' => 'The size must be between 1 and 50.'])->validate();
        $size = (int) ($valid['size'] ?? UserPrefHelper::get($user, UserPrefKey::ColorGuide_ItemsPerPage));

        $pagination = Appearance::where('owner_id', $user->id)->orderBy('order')->orderBy('id')->paginate($size, page: (int) ($valid['page'] ?? 1));
        $items = $pagination->getCollection()->map(fn(Appearance $a) => $a->private && !$can_manage
            ? ['id' => $a->id, 'label' => $a->label, 'private' => true]
            : $this->camel(ColorGuideHelper::mapAppearance($a)) + ['private' => (bool) $a->private])->values();

        return response()->json([
            'appearances' => $items,
            'pagination' => [
                'currentPage' => $pagination->currentPage(),
                'totalPages' => max(1, $pagination->lastPage()),
                'totalItems' => $pagination->total(),
                'itemsPerPage' => $size,
            ],
            'canManage' => $can_manage,
        ]);
    }

    #[OA\Get(
        path: '/users/{id}/personal-guide/point-history',
        operationId: 'GetUsersIdPersonalGuidePointHistory',
        description: 'The history of slot changes, newest first. Requires being the user, or staff',
        tags: ['personal guide'],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/OneBasedId'),
            ),
            new OA\Parameter(in: 'query', name: 'page', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(
                in: 'query',
                name: 'size',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['entries', 'pagination'],
                    properties: [
                        new OA\Property(
                            property: 'entries',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                required: ['id', 'changeType', 'reason', 'amount', 'data', 'createdAt'],
                                properties: [
                                    new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
                                    new OA\Property(property: 'changeType', type: 'string'),
                                    new OA\Property(property: 'reason', type: 'string'),
                                    new OA\Property(property: 'amount', type: 'number'),
                                    new OA\Property(
                                        property: 'data',
                                        type: 'object',
                                        nullable: true,
                                        description: 'What the change was about. Only staff see who granted points (`by`, with their name as `byName`); entries about a post or an appearance say whether it is `gone`, appearances also whether they are `private` and the `previewColor` of public ones',
                                    ),
                                    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                                ],
                            ),
                        ),
                        new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
                    ],
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not signed in',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '403',
                description: 'Insufficient permissions',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function pointHistory(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $visitor = $request->user();
        if ($visitor->id !== $user->id && !$visitor->isStaff()) {
            throw new AuthorizationException();
        }

        $valid = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'between:1,100'],
        ], ['size.between' => 'The size must be between 1 and 100.'])->validate();
        $size = (int) ($valid['size'] ?? 20);

        // The history is derived data, build it the first time somebody asks for it
        if (!PcgSlotHistory::where('user_id', $user->id)->exists()) {
            $user->recalculatePcgSlotHistory();
        }
        $is_staff = $visitor->isStaff();
        $pagination = PcgSlotHistory::where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->paginate($size, page: (int) ($valid['page'] ?? 1));

        // What the entries point at, looked up once for the page: who granted points (staff only), which posts and appearances are still there
        $entries = $pagination->getCollection();
        $ids = fn (array $types, string $key) => $entries->filter(fn (PcgSlotHistory $e) => in_array($e->change_type, $types, true) && is_array($e->change_data) && isset($e->change_data[$key]))
            ->map(fn (PcgSlotHistory $e) => $e->change_data[$key])->unique()->values();
        $granters = $is_staff ? User::whereIn('id', $ids(['manual_give', 'manual_take'], 'by'))->pluck('name', 'id') : collect();
        $posts = Post::whereIn('id', $ids(['post_approved', 'post_unapproved'], 'id'))->pluck('id')->flip();
        $appearances = Appearance::whereIn('id', $ids(['appearance_add', 'appearance_del'], 'id'))->get()->keyBy('id');

        return response()->json([
            'entries' => $entries->map(function (PcgSlotHistory $entry) use ($is_staff, $granters, $posts, $appearances) {
                $data = $entry->change_data;
                if (is_array($data) && !$is_staff) {
                    unset($data['by']);
                }
                if (is_array($data)) {
                    if ($is_staff && isset($data['by'])) {
                        $data['byName'] = $granters[$data['by']] ?? null;
                    }
                    if (in_array($entry->change_type, ['post_approved', 'post_unapproved'], true) && isset($data['id'])) {
                        $data['gone'] = !isset($posts[$data['id']]);
                    }
                    if (in_array($entry->change_type, ['appearance_add', 'appearance_del'], true) && isset($data['id'])) {
                        $appearance = $appearances[$data['id']] ?? null;
                        $data['gone'] = $appearance === null;
                        $data['private'] = (bool) $appearance?->private;
                        // The first color of the preview, drawn as a small square next to the name like the old page did
                        $data['previewColor'] = $appearance && !$appearance->private ? ($appearance->preview_data[0] ?? null) : null;
                    }
                }

                return [
                    'id' => $entry->id,
                    'changeType' => $entry->change_type,
                    'reason' => PcgSlotHistory::CHANGE_DESC[$entry->change_type] ?? $entry->change_type,
                    'amount' => (float) $entry->change_amount,
                    'data' => $data,
                    'createdAt' => $entry->created_at->toIso8601String(),
                ];
            })->values(),
            'pagination' => [
                'currentPage' => $pagination->currentPage(),
                'totalPages' => max(1, $pagination->lastPage()),
                'totalItems' => $pagination->total(),
                'itemsPerPage' => $size,
            ],
        ]);
    }

    #[OA\Post(
        path: '/users/{id}/personal-guide/point-history/recalculation',
        operationId: 'PostUsersIdPersonalGuidePointHistoryRecalculation',
        description: 'Rebuild the slot history of a user from approved requests, appearances and manual grants. Requires developer permission',
        tags: ['personal guide'],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/OneBasedId'),
            ),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Rebuilt'),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function recalculate(int $id): Response
    {
        User::findOrFail($id)->recalculatePcgSlotHistory();

        return response()->noContent();
    }

    #[OA\Get(
        path: '/users/{id}/personal-guide/slots',
        operationId: 'GetUsersIdPersonalGuideSlots',
        description: 'Whether the user can create another Personal Color Guide appearance. 204 means yes',
        tags: ['personal guide'],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/OneBasedId'),
            ),
        ],
        responses: [
            new OA\Response(response: '204', description: 'A slot is available'),
            new OA\Response(
                response: '401',
                description: 'Not signed in',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '403',
                description: 'Creating personal guide appearances is switched off for the user',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '409',
                description: 'No slots left',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function slots(Request $request, int $id): Response
    {
        $user = User::findOrFail($id);
        if (!UserPrefHelper::get($user, UserPrefKey::Admin_CanMakePcgAppearances)) {
            throw new AuthorizationException('You are not allowed to create appearances in your Personal Color Guide');
        }

        if ($user->pcgAvailablePoints() < 10) {
            $same_user = $user->id === $request->user()->id;
            $you = $same_user ? 'You' : $user->name;
            $have = $same_user ? 'have' : 'has';
            $they = $same_user ? 'you' : 'they';
            $continuation = perm(Role::Member, $user->role)
                ? ", but $they can always fulfill some requests"
                : '. '.($same_user ? 'Consider joining the group and fulfilling some requests on our site' : 'They should join the group and fulfill some requests on our site');
            throw new HttpException(409, "$you $have no available slots left$continuation to get more, or delete/edit ones $they've added already.");
        }

        return response()->noContent();
    }

    #[OA\Get(
        path: '/users/{id}/personal-guide/points',
        operationId: 'GetUsersIdPersonalGuidePoints',
        description: 'How many points the user has beyond the 10 needed per appearance. Requires staff',
        tags: ['personal guide'],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/OneBasedId'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['amount'],
                    properties: [new OA\Property(property: 'amount', type: 'integer')],
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function points(int $id): JsonResponse
    {
        return response()->json(['amount' => User::findOrFail($id)->pcgAvailablePoints() - 10]);
    }

    #[OA\Post(
        path: '/users/{id}/personal-guide/points',
        operationId: 'PostUsersIdPersonalGuidePoints',
        description: 'Give or take points. The user cannot go below 10 points. Requires staff',
        tags: ['personal guide'],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/OneBasedId'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['amount'],
                properties: [
                    new OA\Property(
                        property: 'amount',
                        type: 'integer',
                        description: 'Positive to give, negative to take',
                    ),
                    new OA\Property(property: 'comment', type: 'string', minLength: 2, maxLength: 140),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Recorded',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['message'],
                    properties: [new OA\Property(property: 'message', type: 'string')],
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function givePoints(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $valid = Validator::make($request->all(), [
            'amount' => ['required', 'integer', 'not_in:0'],
            'comment' => ['sometimes', 'nullable', 'string', 'between:2,140', 'regex:/^[ -~\n]+$/'],
        ], [
            'amount.required' => 'Amount of slots to give is missing',
            'amount.integer' => 'Amount of slots to give is invalid',
            'amount.not_in' => "You have to enter an integer that isn't 0",
            'comment.between' => 'Comment must be between 2 and 140 chars',
        ])->validate();
        $amount = (int) $valid['amount'];

        if ($user->pcgAvailablePoints() + $amount < 10) {
            return response()->json(['message' => 'This would cause the users points to go below 10', 'errors' => ['amount' => ['This would cause the users points to go below 10']]], 422);
        }

        PcgPointGrant::record($user->id, $request->user()->id, $amount, $valid['comment'] ?? null);

        $points = abs($amount).' point'.(abs($amount) === 1 ? '' : 's');

        return response()->json(['message' => "You've successfully ".($amount > 0 ? 'given' : 'taken')." $points ".($amount > 0 ? 'to' : 'from')." {$user->name}"], 201);
    }

    private function camel(array $data): array
    {
        return json_decode(response()->camelJson($data)->getContent(), true);
    }
}
