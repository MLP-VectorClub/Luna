<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Show;
use App\Utils\DeviantArt;
use App\Exceptions\ImageProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class PostsController extends Controller
{
    #[OA\Get(
        path: '/posts',
        operationId: 'GetPosts',
        description: 'The requests or reservations of a show. Broken posts are only listed for staff',
        tags: ['posts'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'query',
                name: 'showId',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/OneBasedId'),
            ),
            new OA\Parameter(
                in: 'query',
                name: 'kind',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['request', 'reservation']),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['posts'],
                    properties: [
                        new OA\Property(
                            property: 'posts',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/PostItem'),
                        ),
                    ],
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Show not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $valid = Validator::make($request->query(), [
            'showId' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'in:'.implode(',', Post::KINDS)],
        ], [
            'showId.required' => 'The show ID must be a positive integer.',
            'showId.integer' => 'The show ID must be a positive integer.',
            'showId.min' => 'The show ID must be a positive integer.',
            'kind.required' => 'The kind must be either request or reservation.',
            'kind.in' => 'The kind must be either request or reservation.',
        ])->validate();

        $show = Show::findOrFail($valid['showId']);
        $viewer = $request->user();
        $requests = $valid['kind'] === 'request';

        $posts = Post::with(['requester', 'reserver', 'approval.user'])->where('show_id', $show->id)
            ->when($requests, fn($query) => $query->whereNotNull('requested_by'), fn($query) => $query->whereNull('requested_by'))
            ->when(!($viewer?->isStaff() ?? false), fn($query) => $query->where('broken', false))
            ->orderByRaw('finished_at asc nulls last')
            ->orderBy($requests ? 'requested_at' : 'reserved_at')
            ->orderBy('id')
            ->get();

        return response()->json(['posts' => $posts->map(fn(Post $post) => $post->toContract($viewer))->values()]);
    }

    #[OA\Get(
        path: '/posts/{id}/deviation',
        operationId: 'GetPostsIdDeviation',
        description: 'The DeviantArt submission a finished post points at (title, author and images from DeviantArt\'s cached oEmbed data). It is a separate request because looking it up can take a moment, pages load it as the post scrolls into view. Broken posts are only available to staff',
        tags: ['posts'],
        security: [],
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
                    required: ['id', 'title', 'author', 'previewUrl', 'fullsizeUrl'],
                    properties: [
                        new OA\Property(
                            property: 'id',
                            type: 'string',
                            description: 'Submission ID (https://fav.me/{id})',
                        ),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'author', type: 'string', nullable: true),
                        new OA\Property(property: 'previewUrl', type: 'string', nullable: true),
                        new OA\Property(property: 'fullsizeUrl', type: 'string', nullable: true),
                    ],
                ),
            ),
            new OA\Response(
                response: '202',
                description: 'The details are being fetched in the background, ask again after retryAfter seconds',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['pending', 'retryAfter'],
                    properties: [
                        new OA\Property(property: 'pending', type: 'boolean'),
                        new OA\Property(property: 'retryAfter', type: 'integer'),
                    ],
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'No such post, the post is not finished, or DeviantArt does not know the submission',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '502',
                description: 'DeviantArt could not be reached',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function deviation(Request $request, int $id): JsonResponse
    {
        $post = Post::findOrFail($id);
        if ($post->broken && !($request->user()?->isStaff() ?? false)) {
            abort(404);
        }
        if ($post->deviation_id === null) {
            abort(404, 'The post is not finished');
        }

        try {
            $submission = DeviantArt::lookup($post->deviation_id);
        } catch (ImageProviderException $e) {
            abort(502, 'DeviantArt could not be reached');
        }
        if ($submission === DeviantArt::PENDING) {
            return response()->json(['pending' => true, 'retryAfter' => 5], 202);
        }
        if ($submission === null) {
            abort(404, 'The submission could not be found');
        }

        return response()->json([
            'id' => $submission->id,
            'title' => $submission->title,
            'author' => $submission->author,
            'previewUrl' => $submission->preview,
            'fullsizeUrl' => $submission->fullsize,
        ]);
    }

    #[OA\Get(
        path: '/posts/requests/suggestion',
        operationId: 'GetPostsRequestsSuggestion',
        description: 'A random request that is still open (not finished, and not reserved or reserved more than 3 weeks ago) for the Request Roulette on the profile page. Pass the IDs that were suggested already to get a different one each time. Requires a signed in user',
        tags: ['posts'],
        parameters: [
            new OA\Parameter(
                in: 'query',
                name: 'alreadyLoaded',
                required: false,
                description: 'Comma separated post IDs not to suggest again',
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['post'],
                    properties: [
                        new OA\Property(
                            property: 'post',
                            allOf: [
                                new OA\Schema(ref: '#/components/schemas/PostItem'),
                                new OA\Schema(
                                    type: 'object',
                                    required: ['show'],
                                    properties: [
                                        new OA\Property(property: 'show', ref: '#/components/schemas/ShowListItem'),
                                    ],
                                ),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not signed in',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '404',
                description: 'There is nothing left to suggest',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function suggestion(Request $request): JsonResponse
    {
        $loaded = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('alreadyLoaded', ''))), fn(int $id) => $id > 0));

        $query = Post::with(['requester', 'reserver', 'show'])->whereNotNull('requested_by')->whereNull('deviation_id')->where('broken', false)
            ->where(fn($q) => $q->whereNull('reserved_by')->orWhere('reserved_at', '<', now()->subWeeks(3)))
            ->when($loaded !== [], fn($q) => $q->whereNotIn('id', $loaded));
        $post = $query->inRandomOrder()->first();
        if ($post === null) {
            abort(404, ($loaded !== [] ? "You've gone through all" : 'There are no').' available requests, check back later.');
        }

        return response()->json(['post' => $post->toContract($request->user()) + ['show' => ShowController::mapShowListItem($post->show)]]);
    }
}
