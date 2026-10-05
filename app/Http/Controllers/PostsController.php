<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Show;
use App\Utils\DeviantArt;
use App\Exceptions\ImageProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;

class PostsController extends Controller
{
    /**
     * @OA\Get(
     *   path="/posts",
     *   operationId="GetPosts",
     *   description="The requests or reservations of a show. Broken posts are only listed for staff",
     *   tags={"posts"},
     *   security={},
     *   @OA\Parameter(in="query", name="showId", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="query", name="kind", required=true, @OA\Schema(type="string", enum={"request", "reservation"})),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"posts"}, @OA\Property(property="posts", type="array", @OA\Items(ref="#/components/schemas/PostItem")))),
     *   @OA\Response(response="404", description="Show not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
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

        $posts = Post::with(['requester', 'reserver'])->where('show_id', $show->id)
            ->when($requests, fn($query) => $query->whereNotNull('requested_by'), fn($query) => $query->whereNull('requested_by'))
            ->when(!($viewer?->isStaff() ?? false), fn($query) => $query->where('broken', false))
            ->orderByRaw('finished_at asc nulls last')
            ->orderBy($requests ? 'requested_at' : 'reserved_at')
            ->orderBy('id')
            ->get();

        return response()->json(['posts' => $posts->map(fn(Post $post) => $post->toContract($viewer))->values()]);
    }

    /**
     * @OA\Get(
     *   path="/posts/{id}/deviation",
     *   operationId="GetPostsIdDeviation",
     *   description="The DeviantArt submission a finished post points at (title, author and images from DeviantArt's cached oEmbed data). It is a separate request because looking it up can take a moment, pages load it as the post scrolls into view. Broken posts are only available to staff",
     *   tags={"posts"},
     *   security={},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"id", "title", "author", "previewUrl", "fullsizeUrl"},
     *     @OA\Property(property="id", type="string", description="Submission ID (https://fav.me/{id})"),
     *     @OA\Property(property="title", type="string"),
     *     @OA\Property(property="author", type="string", nullable=true),
     *     @OA\Property(property="previewUrl", type="string", nullable=true),
     *     @OA\Property(property="fullsizeUrl", type="string", nullable=true)
     *   )),
     *   @OA\Response(response="404", description="No such post, the post is not finished, or DeviantArt does not know the submission", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="502", description="DeviantArt could not be reached", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
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
            $submission = DeviantArt::submission($post->deviation_id);
        } catch (ImageProviderException $e) {
            abort(502, 'DeviantArt could not be reached');
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
}
