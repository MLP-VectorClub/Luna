<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Enums\UserPrefKey;
use App\Exceptions\ImageProviderException;
use App\Models\BrokenPost;
use App\Models\DeviantartUser;
use App\Models\LockedPost;
use App\Models\Notification;
use App\Models\PcgSlotHistory;
use App\Models\Post;
use App\Models\Show;
use App\Models\User;
use App\Utils\DeviantArt;
use App\Utils\ImageProvider;
use App\Utils\LogWriter;
use App\Utils\ResolvedImage;
use App\Utils\UserPrefHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PostManagementController extends Controller
{
    private const RESERVATION_LIMIT = 4;
    private const THANKS = 'Thank you for your contribution! ;)';

    /**
     * @OA\Schema(
     *   schema="PostInput",
     *   type="object",
     *   required={"kind", "showId", "imageUrl"},
     *   @OA\Property(property="kind", type="string", enum={"request", "reservation"}),
     *   @OA\Property(property="showId", type="integer", description="ID of the show entry this post belongs to"),
     *   @OA\Property(property="imageUrl", type="string", description="URL of the image or deviation to post (see POST /posts/check-image for what is accepted)"),
     *   @OA\Property(property="label", type="string", minLength=3, maxLength=255, description="What the post is about; required for requests, optional for reservations"),
     *   @OA\Property(property="type", type="string", enum={"chr", "obj", "bg"}, description="What a request asks for; required for requests, ignored for reservations"),
     *   @OA\Property(property="postAs", type="string", description="Developer-only: DeviantArt username to post as"),
     *   @OA\Property(property="allowNonmember", type="boolean", description="Developer-only: post a reservation as a user who is not a club member")
     * )
     * @OA\Post(
     *   path="/posts",
     *   operationId="PostPosts",
     *   description="Create a request or reservation from an image link. Needs the `a_postreq` / `a_postres` preference, reservations also need membership and a free reservation slot",
     *   tags={"posts"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/PostInput")),
     *   @OA\Response(response="201", description="Created", @OA\JsonContent(type="object", required={"id", "kind"}, additionalProperties=false,
     *     @OA\Property(property="id", ref="#/components/schemas/OneBasedId"), @OA\Property(property="kind", type="string", enum={"request", "reservation"})
     *   )),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Not allowed to post this kind", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="Conflict, e.g. the image was used already or the reservation limit is reached", @OA\JsonContent(type="object", required={"message"}, @OA\Property(property="message", type="string"), @OA\Property(property="canForce", type="boolean"))),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse")),
     *   @OA\Response(response="502", description="The image could not be retrieved", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function create(Request $request): JsonResponse
    {
        $user = $request->user();
        $valid = Validator::make($request->all(), ['kind' => ['required', 'in:'.implode(',', Post::KINDS)]], [
            'kind.required' => 'Post type is missing',
            'kind.in' => 'Post type ('.$request->input('kind').') is invalid',
        ])->validate();
        $kind = $valid['kind'];
        $is_request = $kind === 'request';

        if (!UserPrefHelper::get($user, $is_request ? UserPrefKey::Admin_CanPostRequests : UserPrefKey::Admin_CanPostReservations)) {
            throw new AuthorizationException("You are not allowed to post {$kind}s");
        }
        if (!$is_request) {
            if (!perm(Role::Member, $user->role)) {
                throw new AuthorizationException();
            }
            $this->assertBelowReservationLimit($user);
        }

        $image = $this->checkImage($request->input('imageUrl'));

        $show_id = Validator::make($request->all(), ['showId' => ['required', 'integer', 'min:1']], [
            'showId.required' => 'Show entry ID is missing',
            'showId.integer' => 'Show entry ID is invalid',
        ])->validate()['showId'];
        if (!Show::whereKey($show_id)->exists()) {
            $this->fail('showId', 'The specified show entry does not exist');
        }

        $by_id = $user->id;
        if (perm(Role::Developer, $user->role) && $request->filled('postAs')) {
            $post_as = DeviantartUser::where('name', $request->input('postAs'))->first()?->user;
            if ($post_as === null) {
                $this->fail('postAs', 'The user you wanted to post as does not exist');
            }
            if (!$is_request && !perm(Role::Member, $post_as->role) && !$request->boolean('allowNonmember')) {
                return response()->json(['message' => 'The user you wanted to post as is not a club member, do you want to post as them anyway?', 'canForce' => true], 409);
            }
            $by_id = $post_as->id;
        }

        $post = new Post(['preview' => $image->preview, 'fullsize' => $image->fullsize, 'show_id' => $show_id]);
        $post->{$is_request ? 'requested_by' : 'reserved_by'} = $by_id;
        $post->{$is_request ? 'requested_at' : 'reserved_at'} = now();
        $this->applyDetails($request, $post, null, $user);
        $post->save();

        return response()->json(['id' => $post->id, 'idString' => "post-{$post->id}", 'kind' => $kind], 201);
    }

    /**
     * @OA\Post(
     *   path="/posts/check-image",
     *   operationId="PostPostsCheckImage",
     *   description="Check whether an image link can be used for a post and get its preview",
     *   tags={"posts"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"imageUrl"}, @OA\Property(property="imageUrl", type="string", description="URL of the image/deviation to check"))),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"preview", "title"}, additionalProperties=false, @OA\Property(property="preview", type="string"), @OA\Property(property="title", type="string", nullable=true))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function checkImageEndpoint(Request $request): JsonResponse
    {
        $image = $this->checkImage($request->input('imageUrl'));

        return response()->json(['preview' => $image->preview, 'title' => $image->title]);
    }

    /**
     * @OA\Get(
     *   path="/posts/{id}",
     *   operationId="GetPostsId",
     *   description="The editable fields of a post. Requires being the poster of an unreserved request or the reserver of a reservation, or staff",
     *   tags={"posts"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/Post")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="The post is approved", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $post = $this->loadPost($id);
        $this->assertCanEdit($request->user(), $post);
        $developer = perm(Role::Developer, $request->user()->role);

        $result = ['label' => $post->label];
        if ($post->isRequest()) {
            $result['type'] = $post->type;
            if ($developer && $post->reserved_by !== null) {
                $result['reservedAt'] = $post->reserved_at?->toIso8601String() ?? '';
            }
        }
        if ($developer) {
            $result['postedAt'] = $post->postedAt()?->toIso8601String();
            if ($post->reserved_by !== null && $post->deviation_id !== null) {
                $result['finishedAt'] = $post->finished_at?->toIso8601String() ?? '';
            }
        }

        return response()->json($result);
    }

    /**
     * @OA\Put(
     *   path="/posts/{id}",
     *   operationId="PutPostsId",
     *   description="Edit a post's description and type. Only fields that are present and changed are updated",
     *   tags={"posts"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(@OA\JsonContent(type="object",
     *     @OA\Property(property="label", type="string", nullable=true, minLength=3, maxLength=255),
     *     @OA\Property(property="type", type="string", enum={"chr", "obj", "bg"}),
     *     @OA\Property(property="postedAt", type="string", format="date-time", description="Developer-only"),
     *     @OA\Property(property="reservedAt", type="string", format="date-time", nullable=true, description="Developer-only"),
     *     @OA\Property(property="finishedAt", type="string", format="date-time", nullable=true, description="Developer-only")
     *   )),
     *   @OA\Response(response="204", description="Saved"),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(Request $request, int $id): Response
    {
        $post = $this->loadPost($id);
        $user = $request->user();
        $this->assertCanEdit($user, $post);

        $this->applyDetails($request, $post, $post, $user);
        $post->save();

        return response()->noContent();
    }

    /**
     * @OA\Post(
     *   path="/posts/{id}/reservation",
     *   operationId="PostPostsIdReservation",
     *   description="Reserve a request, or take over one that has been reserved for over 3 weeks. Requires membership. Developers can reserve it for another user with `as`",
     *   tags={"posts"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=false, @OA\JsonContent(type="object",
     *     @OA\Property(property="as", type="string", description="Developers only: the DeviantArt name of the user to reserve the request for"),
     *     @OA\Property(property="screwit", type="boolean", description="Developers only: reserve for a user without the permission to reserve anyway (the 409 with `retry` asks for it)")
     *   )),
     *   @OA\Response(response="200", description="Reserved", @OA\JsonContent(type="object", required={"post"}, @OA\Property(property="post", ref="#/components/schemas/PostItem"))),
     *   @OA\Response(response="403", description="Not allowed to reserve", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="Already reserved, broken, over the reservation limit, or not a request", @OA\JsonContent(type="object", required={"message"},
     *     @OA\Property(property="message", type="string"), @OA\Property(property="post", ref="#/components/schemas/PostItem"), @OA\Property(property="retry", type="boolean")
     *   ))
     * )
     */
    public function reserve(Request $request, int $id): JsonResponse
    {
        $post = $this->loadPost($id);
        $user = $request->user();
        if (!$post->isRequest()) {
            throw new HttpException(409, 'This endpoint only acts on requests');
        }

        $old_reserver = $post->reserved_by;
        if ($old_reserver === null) {
            if (!UserPrefHelper::get($user, UserPrefKey::Admin_CanReservePosts)) {
                throw new AuthorizationException('You are not allowed to reserve requests');
            }
            if ($post->broken) {
                throw new HttpException(409, 'Broken posts cannot be reserved. The image must be updated'.($user->isStaff() ? ' or the broken status cleared' : '').' via the edit menu to make the post reservable.');
            }
            $this->assertBelowReservationLimit($user);

            $post->reserved_by = $user->id;
            $this->applyReserveAs($request, $user, $post);
            $post->reserved_at = now();
        } else {
            if ($old_reserver === $user->id) {
                return response()->json(['message' => "You've already reserved this request", 'post' => $post->toContract($user)], 409);
            }
            if (!$post->isOverdue()) {
                return response()->json([
                    'message' => "This request has already been reserved by {$post->reserver->name}",
                    'post' => $post->toContract($user),
                    'reservedBy' => ['id' => $post->reserver->id, 'name' => $post->reserver->name],
                ], 409);
            }

            $overdue = ['reserved_by' => $post->reserved_by, 'reserved_at' => $post->reserved_at?->toIso8601String(), 'id' => $post->id];
            $post->reserved_by = $user->id;
            $this->applyReserveAs($request, $user, $post);
            $post->reserved_at = now();
        }
        $post->save();

        if (isset($overdue)) {
            LogWriter::record('res_overtake', $overdue);
        }

        return response()->json(['post' => $post->fresh(['requester', 'reserver'])->toContract($user)]);
    }

    /**
     * @OA\Delete(
     *   path="/posts/{id}/reservation",
     *   operationId="DeletePostsIdReservation",
     *   description="Cancel a reservation. Deletes reservations, frees requests. Requires being the reserver, or staff",
     *   tags={"posts"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="Cancelled, or the request was not reserved", @OA\JsonContent(type="object", @OA\Property(property="post", ref="#/components/schemas/PostItem"))),
     *   @OA\Response(response="204", description="The reservation was deleted"),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="The post has to be unfinished first", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function unreserve(Request $request, int $id)
    {
        $post = $this->loadPost($id);
        $user = $request->user();
        $can_delete = $post->reserved_by === $user->id || $user->isStaff();

        if ($post->isRequest()) {
            if ($post->reserved_by === null) {
                return response()->json(['post' => $post->toContract($user)]);
            }
            if (!$can_delete) {
                throw new AuthorizationException();
            }
            if ($post->deviation_id !== null) {
                throw new HttpException(409, 'You must unfinish this request before unreserving it.');
            }
            $post->forceFill(['reserved_by' => null, 'reserved_at' => null])->save();

            return response()->json(['post' => $post->fresh(['requester', 'reserver'])->toContract($user)]);
        }

        if (!$can_delete) {
            throw new AuthorizationException();
        }
        if ($post->deviation_id !== null) {
            throw new HttpException(409, 'You must unfinish this reservation before deleting it.');
        }
        $post->delete();

        return response()->noContent();
    }

    /**
     * @OA\Post(
     *   path="/posts/{id}/approval",
     *   operationId="PostPostsIdApproval",
     *   description="Mark a finished post as approved once it appears in the club gallery. Requires membership",
     *   tags={"posts"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="Approved", @OA\JsonContent(type="object", @OA\Property(property="message", type="string"), @OA\Property(property="post", ref="#/components/schemas/PostItem"))),
     *   @OA\Response(response="409", description="Not reserved, not finished, or not in the gallery yet", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="502", description="The gallery could not be checked", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $post = $this->loadPost($id);
        $user = $request->user();
        if ($post->reserved_by === null) {
            throw new HttpException(409, 'This post has not been reserved by anypony yet');
        }
        if (empty($post->deviation_id)) {
            throw new HttpException(409, 'Only finished posts can be approved');
        }
        $this->assertInClubGallery($post->deviation_id);

        DB::transaction(function () use ($post, $user) {
            $post->forceFill(['lock' => true])->save();
            LockedPost::create(['post_id' => $post->id, 'user_id' => $user->id]);

            if (UserPrefHelper::get($post->reserver, UserPrefKey::Admin_CanEarnPcgPoints)) {
                PcgSlotHistory::record($post->reserver->id, 'post_approved', null, ['id' => $post->id]);
                $post->reserver->syncPcgSlotCount();
            }
            if ($post->reserved_by !== $user->id) {
                Notification::send($post->reserved_by, 'post-approved', ['id' => $post->id]);
            }
        });

        $message = 'The image appears to be in the group gallery and as such it is now marked as approved.';
        if ($post->reserved_by === $user->id) {
            $message .= ' '.self::THANKS;
        }

        return response()->json(['message' => $message, 'post' => $post->fresh(['requester', 'reserver'])->toContract($user)]);
    }

    /**
     * @OA\Delete(
     *   path="/posts/{id}/approval",
     *   operationId="DeletePostsIdApproval",
     *   description="Remove the approval of a post. Posts that are in the club gallery can only be unlocked by developers. Requires staff",
     *   tags={"posts"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="204", description="Unlocked"),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="Not approved, or still in the gallery", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function unapprove(Request $request, int $id): Response
    {
        // Approved posts are locked, so removing the approval has to be allowed to load them
        $post = $this->loadPost($id, check_lock: false);
        $user = $request->user();
        if (!$user->isStaff()) {
            throw new AuthorizationException();
        }
        if (!$post->lock) {
            throw new HttpException(409, 'This post has not been approved yet');
        }
        if (!perm(Role::Developer, $user->role) && DeviantArt::isDeviationInClub($post->deviation_id) === true) {
            throw new HttpException(409, "The deviation (http://fav.me/{$post->deviation_id}) is part of the group gallery, which prevents the post from being unlocked.");
        }

        $post->forceFill(['lock' => false])->save();
        // Only deduct points if the reserver isn't also the requester
        if ($post->reserved_by !== $post->requested_by && $post->reserved_by !== null) {
            PcgSlotHistory::record($post->reserved_by, 'post_unapproved', null, ['id' => $post->id]);
            $post->reserver->syncPcgSlotCount();
        }

        return response()->noContent();
    }

    /**
     * @OA\Put(
     *   path="/posts/{id}/finish",
     *   operationId="PutPostsIdFinish",
     *   description="Mark a reserved post as finished with a deviation. Requires membership and being the reserver, or staff",
     *   tags={"posts"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"deviation"},
     *     @OA\Property(property="deviation", type="string", description="URL of the finished deviation"),
     *     @OA\Property(property="allowOverwriteReserver", type="boolean", description="Send this when the 409 `retry` response asks for it: makes the deviation's author the new reserver"),
     *     @OA\Property(property="finishedAt", type="string", format="date-time", description="Developer-only: overrides the finished timestamp")
     *   )),
     *   @OA\Response(response="200", description="Finished, with a message about approval and notifications", @OA\JsonContent(type="object", required={"message", "approved", "notified"},
     *     @OA\Property(property="message", type="string"), @OA\Property(property="approved", type="boolean"), @OA\Property(property="notified", ref="#/components/schemas/PostUser", nullable=true)
     *   )),
     *   @OA\Response(response="204", description="Finished"),
     *   @OA\Response(response="409", description="Not reserved, the deviation is used already, or the author differs from the reserver", @OA\JsonContent(type="object", required={"message"},
     *     @OA\Property(property="message", type="string"), @OA\Property(property="retry", type="boolean", description="Repeat the request with allowOverwriteReserver set"), @OA\Property(property="existingPost", type="object")
     *   )),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function finish(Request $request, int $id)
    {
        $post = $this->loadPost($id);
        $user = $request->user();
        if ($post->reserved_by === null) {
            throw new HttpException(409, 'This post has not been reserved by anypony yet');
        }
        $is_reserver = $post->reserved_by === $user->id;
        if (!$is_reserver && !$user->isStaff()) {
            throw new AuthorizationException();
        }

        $update = $this->checkFinishingImage($request, $post->reserved_by, $user);
        $finished_at = perm(Role::Developer, $user->role) && $request->filled('finishedAt') ? $this->timestamp('finishedAt', $request->input('finishedAt')) : null;
        $update['finished_at'] = $finished_at ?? now();
        $post->forceFill($update)->save();

        $message = '';
        $approved = false;
        if (isset($update['lock'])) {
            $approved = true;
            LockedPost::create(['post_id' => $post->id, 'user_id' => $user->id]);
            if ($is_reserver) {
                $message .= self::THANKS.' ';
            } else {
                Notification::send($post->reserved_by, 'post-approved', ['id' => $post->id]);
            }
            $message .= "The post has been approved automatically because it's already in the club gallery.";
        }

        $notified = null;
        if ($post->isRequest() && $post->requested_by !== $user->id) {
            Notification::send($post->requested_by, 'post-finished', ['id' => $post->id]);
            $message .= ($message !== '' ? ' ' : '')."{$post->requester->name} has been notified.";
            $notified = ['id' => $post->requester->id, 'name' => $post->requester->name];
        }

        return $message !== '' ? response()->json(['message' => $message, 'approved' => $approved, 'notified' => $notified]) : response()->noContent();
    }

    /**
     * @OA\Delete(
     *   path="/posts/{id}/finish",
     *   operationId="DeletePostsIdFinish",
     *   description="Mark a finished post as unfinished. With `unbind` the reservation is removed as well (reservations are deleted). Requires being the reserver, or staff",
     *   tags={"posts"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="query", name="unbind", description="Also remove the reserver", @OA\Schema(type="boolean")),
     *   @OA\Response(response="200", description="The reservation was deleted", @OA\JsonContent(type="object", @OA\Property(property="message", type="string"), @OA\Property(property="remove", type="boolean", description="True if the post was deleted entirely"))),
     *   @OA\Response(response="204", description="Marked as unfinished"),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="Conflict", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function unfinish(Request $request, int $id)
    {
        $post = $this->loadPost($id);
        $user = $request->user();
        $is_reserver = $post->reserved_by === $user->id;
        if (!$is_reserver && !$user->isStaff()) {
            throw new AuthorizationException();
        }

        $update = [];
        if ($request->has('unbind')) {
            if (!$post->isRequest()) {
                $post->delete();

                return response()->json(['message' => 'Reservation deleted', 'remove' => true]);
            }
            $update = ['reserved_by' => null, 'reserved_at' => null];
        } elseif (!$post->isRequest() && empty($post->preview)) {
            throw new HttpException(409, 'This reservation was added directly and cannot be marked unfinished. To remove it, check the unbind from user checkbox.');
        }

        $post->forceFill($update + ['deviation_id' => null, 'finished_at' => null])->save();

        return response()->noContent();
    }

    /**
     * @OA\Get(
     *   path="/posts/{id}/location",
     *   operationId="GetPostsIdLocation",
     *   description="Where a post can be found, for links that point to a post",
     *   tags={"posts"},
     *   security={},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="query", name="showId", description="The show the visitor is looking at", @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false,
     *     @OA\Property(property="refresh", type="string", enum={"request", "reservation"}, description="Set if the post belongs to the show given by showId"),
     *     @OA\Property(property="castle", type="object", description="Set if the post belongs to a different show", additionalProperties=false,
     *       @OA\Property(property="name", type="string", description="Title of the post's show"), @OA\Property(property="showId", type="integer"), @OA\Property(property="postId", type="integer")
     *     )
     *   )),
     *   @OA\Response(response="404", description="The post was deleted or never existed", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function location(Request $request, int $id): JsonResponse
    {
        $post = Post::with('show')->find($id);
        if ($post === null || $post->broken) {
            throw new HttpException(404, "The post you were linked to has either been deleted or didn't exist in the first place. Sorry. :'(");
        }
        if ($request->has('showId') && $post->show_id === (int) $request->input('showId')) {
            return response()->json(['refresh' => $post->kind()]);
        }

        return response()->json(['castle' => ['name' => $post->show->title, 'showId' => $post->show_id, 'postId' => $post->id]]);
    }

    /**
     * @OA\Post(
     *   path="/posts/{id}/unbreak",
     *   operationId="PostPostsIdUnbreak",
     *   description="Clear the broken status of a post whose images work again. Requires staff",
     *   tags={"posts"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", @OA\Property(property="post", ref="#/components/schemas/PostItem"))),
     *   @OA\Response(response="409", description="An image is still unavailable", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function unbreak(Request $request, int $id): JsonResponse
    {
        $post = $this->loadPost($id);
        foreach (['preview', 'fullsize'] as $key) {
            $link = $post->{$key};
            if (!DeviantArt::isImageAvailable((string) $link)) {
                throw new HttpException(409, "The $key image appears to be unavailable. Please make sure this link ($link) works and try again. If it doesn't, you will need to replace the image.");
            }
        }

        // Restore the reserver from when the post was still up, if there was one
        $broken = BrokenPost::where('post_id', $post->id)->orderByDesc('created_at')->orderByDesc('id')->first();
        $post->broken = false;
        if ($broken?->reserved_by !== null) {
            $post->reserved_by = $broken->reserved_by;
        }
        $post->save();

        LogWriter::record('post_fix', ['id' => $post->id, 'reserved_by' => $post->reserved_by]);

        return response()->json(['post' => $post->fresh(['requester', 'reserver'])->toContract($request->user())]);
    }

    /**
     * @OA\Delete(
     *   path="/posts/requests/{id}",
     *   operationId="DeletePostsRequestsId",
     *   description="Delete a request. Its poster can delete it until somebody reserves it, staff always",
     *   tags={"posts"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="204", description="Deleted"),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="Not a request, or already reserved", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroyRequest(Request $request, int $id): Response
    {
        $post = $this->loadPost($id);
        $user = $request->user();
        if (!$post->isRequest()) {
            throw new HttpException(409, 'Only requests can be deleted using this endpoint');
        }
        if (!$user->isStaff()) {
            if ($post->requested_by !== $user->id) {
                throw new AuthorizationException();
            }
            if ($post->reserved_by !== null) {
                throw new HttpException(409, 'You cannot delete a request that has already been reserved by a group member');
            }
        }

        $post->delete();
        LogWriter::record('req_delete', [
            'show_id' => $post->show_id,
            'id' => $post->id,
            'label' => $post->label,
            'type' => $post->type,
            'requested_by' => $post->requested_by,
            'requested_at' => $post->requested_at?->toIso8601String(),
            'reserved_by' => $post->reserved_by,
            'deviation_id' => $post->deviation_id,
            'lock' => $post->lock,
        ]);

        return response()->noContent();
    }

    /**
     * @OA\Put(
     *   path="/posts/{id}/image",
     *   operationId="PutPostsIdImage",
     *   description="Replace the image of a post, which also clears its broken status. Requires being the poster of an unreserved request, or staff",
     *   tags={"posts"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"imageUrl"}, @OA\Property(property="imageUrl", type="string", description="New image URL (deviation or a supported provider)"))),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false,
     *     @OA\Property(property="post", ref="#/components/schemas/PostItem"), @OA\Property(property="preview", type="string", description="New preview image URL")
     *   )),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="The post is locked, reserved, or the image was used already", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function setImage(Request $request, int $id): JsonResponse
    {
        $post = $this->loadPost($id, check_lock: false);
        $user = $request->user();
        if ($post->lock) {
            throw new HttpException(409, 'This post is locked, its image cannot be changed.');
        }
        if (!$user->isStaff()) {
            if ($post->poster()?->id !== $user->id) {
                throw new AuthorizationException();
            }
            if ($post->isRequest() && $post->reserved_by !== null) {
                throw new HttpException(409, 'You cannot change the image of a request that has already been reserved.');
            }
        }

        $image = $this->checkImage($request->input('imageUrl'), $post);
        if (!DeviantArt::isImageAvailable($image->preview)) {
            $this->fail('imageUrl', "The specified image doesn't seem to exist. Please verify that you can reach this URL and try again: {$image->preview}");
        }

        $old = ['preview' => $post->preview, 'fullsize' => $post->fullsize, 'broken' => $post->broken];
        $post->forceFill(['preview' => $image->preview, 'fullsize' => $image->fullsize, 'broken' => false])->save();
        LogWriter::record('img_update', [
            'id' => $post->id,
            'oldpreview' => $old['preview'],
            'oldfullsize' => $old['fullsize'],
            'newpreview' => $post->preview,
            'newfullsize' => $post->fullsize,
        ]);

        $result = ['preview' => $image->preview];
        if ($old['broken']) {
            $result['post'] = $post->fresh(['requester', 'reserver'])->toContract($user);
        }

        return response()->json($result);
    }

    /**
     * @OA\Post(
     *   path="/posts/reservations",
     *   operationId="PostPostsReservations",
     *   description="Add an already finished reservation directly from a deviation. Requires staff",
     *   tags={"posts"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"showId", "deviation"},
     *     @OA\Property(property="showId", type="integer", description="ID of the show entry this reservation belongs to"),
     *     @OA\Property(property="deviation", type="string", description="URL of the finished deviation")
     *   )),
     *   @OA\Response(response="201", description="Added", @OA\JsonContent(type="object", required={"id"}, additionalProperties=false, @OA\Property(property="id", ref="#/components/schemas/OneBasedId"), @OA\Property(property="message", type="string"))),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function addReservation(Request $request): JsonResponse
    {
        $user = $request->user();
        // The deviation's author becomes the reserver, so the author check is skipped
        $request->merge(['allowOverwriteReserver' => true]);
        $insert = $this->checkFinishingImage($request, null, $user);
        $insert['reserved_by'] ??= $user->id;

        $show_id = Validator::make($request->all(), ['showId' => ['required', 'integer', 'min:1']], [
            'showId.required' => 'Show ID is missing',
            'showId.integer' => 'Show ID is invalid',
        ])->validate()['showId'];
        if (!Show::whereKey($show_id)->exists()) {
            $this->fail('showId', 'The specified show entry does not exist');
        }

        $reservation = new Post($insert + ['show_id' => $show_id, 'reserved_at' => now(), 'finished_at' => now()]);
        $reservation->save();
        if (!empty($insert['lock'])) {
            LockedPost::create(['post_id' => $reservation->id, 'user_id' => $user->id]);
        }

        return response()->json(['message' => 'Reservation added', 'id' => $reservation->id, 'idString' => "post-{$reservation->id}"], 201);
    }

    private function loadPost(int $id, bool $check_lock = true): Post
    {
        $post = Post::with(['requester', 'reserver', 'show'])->findOrFail($id);
        if ($check_lock && $post->lock && !perm(Role::Developer, request()->user()->role)) {
            throw new HttpException(409, 'This post has been approved and cannot be edited or removed.');
        }

        return $post;
    }

    private function assertCanEdit(User $user, Post $post): void
    {
        // Requests can be edited by their requester until somebody reserves them, reservations by their reserver, everything by staff
        if (!$post->canBeEditedBy($user)) {
            throw new AuthorizationException();
        }
    }

    private function assertBelowReservationLimit(User $user): void
    {
        $count = Post::where('reserved_by', $user->id)->whereNull('deviation_id')->count();
        if ($count >= self::RESERVATION_LIMIT) {
            throw new HttpException(409, "You've already reserved $count images, and you can't have more than ".self::RESERVATION_LIMIT.' pending reservations at a time. Finish at least one of them before trying to reserve another image.');
        }
    }

    private function assertInClubGallery(string $deviation_id): void
    {
        $status = DeviantArt::isDeviationInClub($deviation_id);
        if ($status !== true) {
            throw new HttpException($status === false ? 409 : 502, $status === false
                ? 'The deviation has not been submitted to/accepted by the group yet'
                : "There was an issue while checking the acceptance status (Error code: $status)");
        }
    }

    private function checkImage($url, ?Post $post = null): ResolvedImage
    {
        if (!is_string($url) || trim($url) === '') {
            $this->fail('imageUrl', 'Please provide an image URL.');
        }
        try {
            $image = ImageProvider::resolve($url);
        } catch (ImageProviderException $e) {
            $this->fail('imageUrl', $e->getMessage());
        }

        if ($image->preview !== null && $post !== null) {
            $used = Post::with('show')->where('preview', $image->preview)->where('id', '!=', $post->id)->first();
            if ($used !== null) {
                throw new ConflictException("This exact image has already been used for a {$used->kind()} under {$used->show->title}", ['existingPost' => ['id' => $used->id, 'kind' => $used->kind()]]);
            }
        }

        return $image;
    }

    /**
     * @return array{deviation_id: string, reserved_by?: int, lock?: bool}
     */
    private function checkFinishingImage(Request $request, ?int $reserver_id, User $user): array
    {
        $url = $request->input('deviation');
        if (!is_string($url) || trim($url) === '') {
            $this->fail('deviation', 'Please specify a deviation URL');
        }

        try {
            $image = ImageProvider::resolve($url, ImageProvider::DEVIATION_PROVIDERS);
        } catch (ImageProviderException $e) {
            $this->fail('deviation', $e->actualProvider !== null
                ? "The finished vector must be uploaded to DeviantArt, {$e->actualProvider} links are not allowed"
                : $e->getMessage());
        }

        $used = Post::with('show')->where('deviation_id', $image->id)->first();
        if ($used !== null) {
            throw new ConflictException("This exact deviation has already been marked as the finished version of a {$used->kind()} under {$used->show->title}", ['existingPost' => ['id' => $used->id, 'kind' => $used->kind()]]);
        }

        $result = ['deviation_id' => $image->id];
        if (!empty($image->author)) {
            $author = DeviantartUser::where('name', $image->author)->first()?->user;
            if ($author === null) {
                throw new HttpException(502, "Could not fetch local user data for username: {$image->author}");
            }

            if (!$request->boolean('allowOverwriteReserver') && $reserver_id !== null && $author->id !== $reserver_id) {
                $same_user = $user->id === $reserver_id;
                $person = $same_user ? 'you' : 'the user who reserved this post';
                throw new ConflictException(
                    "You've linked to an image which was not submitted by $person. If this was intentional, press Continue to proceed with marking the post finished, but note that it will make {$author->name} the new reserver."
                    .($same_user ? " This means that you'll no longer be able to interact with this post until {$author->name} or an administrator cancels the reservation on it." : ''),
                    ['retry' => true]
                );
            }

            $result['reserved_by'] = $author->id;
        }

        if (DeviantArt::isDeviationInClub($image->id) === true) {
            $result['lock'] = true;
        }

        return $result;
    }

    private function applyReserveAs(Request $request, User $user, Post $post): void
    {
        if (!perm(Role::Developer, $user->role) || !$request->filled('as')) {
            return;
        }
        $reserve_as = DeviantartUser::where('name', $request->input('as'))->first()?->user;
        if ($reserve_as === null) {
            $this->fail('as', 'User to reserve as does not exist');
        }
        if (!$request->boolean('screwit') && !perm(Role::Member, $reserve_as->role)) {
            throw new ConflictException('The specified user does not have permission to reserve posts, continue anyway?', ['retry' => true]);
        }
        $post->reserved_by = $reserve_as->id;
    }

    /**
     * Applies the label, type and developer-only timestamps to a new or existing post
     */
    private function applyDetails(Request $request, Post $post, ?Post $existing, User $user): void
    {
        $editing = $existing !== null;
        $label = $request->input('label');
        $validator = Validator::make(['label' => $label === '' ? null : $label, 'type' => $request->input('type')], [
            'label' => ['nullable', 'string', 'between:3,255', 'regex:/^[ -~\n]+$/'],
            'type' => ['nullable', 'in:'.implode(',', array_keys(Post::REQUEST_TYPES))],
        ], [
            'label.between' => 'The description must be between 3 and 255 characters',
            'label.regex' => 'The description contains invalid characters',
            'type.in' => 'Request type ('.$request->input('type').') is invalid',
        ]);
        $valid = $validator->validate();

        if ($valid['label'] !== null) {
            if (!$editing || $valid['label'] !== $existing->label) {
                $post->label = str_replace("''", '"', $valid['label']);
            }
        } elseif (!$editing && $post->isRequest()) {
            $this->fail('label', 'Description cannot be empty');
        } elseif (!$editing || $request->exists('label')) {
            $post->label = null;
        }

        $developer = perm(Role::Developer, $user->role);
        if ($post->isRequest()) {
            if ($valid['type'] === null && !$editing) {
                $this->fail('type', 'Missing request type');
            }
            if ($valid['type'] !== null && (!$editing || $valid['type'] !== $existing->type)) {
                $post->type = $valid['type'];
            }
            if ($developer && $request->exists('reservedAt')) {
                $post->reserved_at = $request->input('reservedAt') === null || $request->input('reservedAt') === '' ? null : $this->timestamp('reservedAt', $request->input('reservedAt'));
            }
        }

        if ($developer) {
            if ($request->filled('postedAt')) {
                $post->{$post->isRequest() ? 'requested_at' : 'reserved_at'} = $this->timestamp('postedAt', $request->input('postedAt'));
            }
            if ($request->exists('finishedAt')) {
                $post->finished_at = $request->filled('finishedAt') ? $this->timestamp('finishedAt', $request->input('finishedAt')) : null;
            }
        }
    }

    private function timestamp(string $field, $value): \Carbon\CarbonInterface
    {
        $time = is_string($value) ? strtotime($value) : false;
        if ($time === false) {
            $this->fail($field, '"'.ucfirst(strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $field)))."\" timestamp ($value) is invalid");
        }

        return \Carbon\Carbon::createFromTimestamp($time);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
