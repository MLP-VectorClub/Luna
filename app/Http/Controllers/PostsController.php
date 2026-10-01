<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Show;
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
}
