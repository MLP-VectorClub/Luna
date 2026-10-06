<?php

namespace App\Http\Controllers;

use App\Exceptions\ImageProviderException;
use App\Models\Event;
use App\Models\EventEntry;
use App\Models\User;
use App\Utils\DeviantArt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;

class EventsController extends Controller
{
    /**
     * @OA\Schema(
     *   schema="EventItem",
     *   description="A community collaboration event",
     *   type="object",
     *   required={"id", "name", "startsAt", "endsAt", "maxEntries", "entryRole", "voteRole", "resultFavMe", "finalizedAt"},
     *   additionalProperties=false,
     *   @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *   @OA\Property(property="name", type="string"),
     *   @OA\Property(property="startsAt", type="string", format="date-time"),
     *   @OA\Property(property="endsAt", type="string", format="date-time"),
     *   @OA\Property(property="maxEntries", type="integer", nullable=true, description="Maximum number of entries a single user may submit"),
     *   @OA\Property(property="entryRole", type="string", nullable=true, description="Minimum role (or special role identifier) required to submit entries"),
     *   @OA\Property(property="voteRole", type="string", nullable=true, description="Minimum role (or special role identifier) required to vote"),
     *   @OA\Property(property="resultFavMe", type="string", nullable=true, description="fav.me ID of the winning entry's deviation, once finalized"),
     *   @OA\Property(property="finalizedAt", type="string", format="date-time", nullable=true)
     * )
     * @OA\Schema(
     *   schema="EventDetails",
     *   type="object",
     *   allOf={
     *     @OA\Schema(ref="#/components/schemas/EventItem"),
     *     @OA\Schema(type="object", required={"descriptionSrc", "descriptionHtml", "addedBy", "createdAt", "canEnter", "canVote", "ongoing", "ended", "entries"},
     *       @OA\Property(property="descriptionSrc", type="string", nullable=true, description="Markdown source of the description"),
     *       @OA\Property(property="descriptionHtml", type="string", description="The description rendered to (already sanitized) HTML"),
     *       @OA\Property(property="addedBy", ref="#/components/schemas/PostUser"),
     *       @OA\Property(property="createdAt", type="string", format="date-time"),
     *       @OA\Property(property="canEnter", type="boolean"),
     *       @OA\Property(property="canVote", type="boolean"),
     *       @OA\Property(property="ongoing", type="boolean"),
     *       @OA\Property(property="ended", type="boolean"),
     *       @OA\Property(property="entries", type="array", @OA\Items(type="object", required={"id", "title", "submittedBy", "submissionProvider", "submissionId", "previewUrl", "fullUrl", "createdAt", "updatedAt"},
     *         @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *         @OA\Property(property="title", type="string"),
     *         @OA\Property(property="submittedBy", ref="#/components/schemas/PostUser"),
     *         @OA\Property(property="submissionProvider", type="string", description="fav.me or sta.sh"),
     *         @OA\Property(property="submissionId", type="string"),
     *         @OA\Property(property="previewUrl", type="string", nullable=true),
     *         @OA\Property(property="fullUrl", type="string", nullable=true),
     *         @OA\Property(property="createdAt", type="string", format="date-time"),
     *         @OA\Property(property="updatedAt", type="string", format="date-time")
     *       ))
     *     )
     *   }
     * )
     * @OA\Get(
     *   path="/events",
     *   operationId="GetEvents",
     *   description="List events, newest first",
     *   tags={"events"},
     *   security={},
     *   @OA\Parameter(in="query", name="page", @OA\Schema(type="integer", minimum=1, default=1)),
     *   @OA\Parameter(in="query", name="size", @OA\Schema(type="integer", minimum=1, maximum=50, default=20)),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"events", "pagination"},
     *     @OA\Property(property="events", type="array", @OA\Items(ref="#/components/schemas/EventItem")),
     *     @OA\Property(property="pagination", ref="#/components/schemas/Pagination")
     *   )),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $valid = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'between:1,50'],
        ], ['size.between' => 'The size must be between 1 and 50.'])->validate();
        $size = (int) ($valid['size'] ?? 20);

        $pagination = Event::orderByDesc('starts_at')->orderByDesc('id')->paginate($size, page: (int) ($valid['page'] ?? 1));

        return response()->json([
            'events' => $pagination->getCollection()->map(fn(Event $event) => $event->toContract())->values(),
            'pagination' => [
                'currentPage' => $pagination->currentPage(),
                'totalPages' => max(1, $pagination->lastPage()),
                'totalItems' => $pagination->total(),
                'itemsPerPage' => $size,
            ],
        ]);
    }

    /**
     * @OA\Get(
     *   path="/events/{id}",
     *   operationId="GetEventsId",
     *   description="An event with its entries. Entering and voting are switched off, so `canEnter` and `canVote` are always false",
     *   tags={"events"},
     *   security={},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/EventDetails")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(int $id): JsonResponse
    {
        $event = Event::with(['creator', 'entries.submitter'])->findOrFail($id);
        $user = fn(?User $u) => $u === null ? null : ['id' => $u->id, 'name' => $u->name];

        return response()->json($event->toContract() + [
            'descriptionSrc' => $event->desc_src,
            'descriptionHtml' => (string) $event->desc_rend,
            'addedBy' => $user($event->creator),
            'createdAt' => $event->created_at->toIso8601String(),
            // Event entries and votes are disabled in Winterchilla and so are they here
            'canEnter' => false,
            'canVote' => false,
            'ongoing' => false,
            'ended' => true,
            'entries' => $event->entries->map(fn(EventEntry $entry) => [
                'id' => $entry->id,
                'title' => $entry->title,
                'submittedBy' => $user($entry->submitter),
                'submissionProvider' => $entry->sub_prov,
                'submissionId' => $entry->sub_id,
                'previewUrl' => $entry->prev_thumb,
                'fullUrl' => $entry->prev_full,
                'createdAt' => $entry->created_at->toIso8601String(),
                'updatedAt' => ($entry->updated_at ?? $entry->created_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * @OA\Get(
     *   path="/events/{id}/finished-image",
     *   operationId="GetEventsIdFinishedImage",
     *   description="The DeviantArt submission that shows the finished collaboration (the event's result), loaded from DeviantArt when asked for",
     *   tags={"events"},
     *   security={},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"id", "title", "author", "previewUrl", "fullsizeUrl"},
     *     @OA\Property(property="id", type="string"),
     *     @OA\Property(property="title", type="string", nullable=true),
     *     @OA\Property(property="author", type="string", nullable=true),
     *     @OA\Property(property="previewUrl", type="string"),
     *     @OA\Property(property="fullsizeUrl", type="string"))),
     *   @OA\Response(response="404", description="Unknown event, no result yet or the submission could not be found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="502", description="DeviantArt could not be reached", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function finishedImage(int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        abort_if($event->result_favme === null, 404, 'The event has no finished image');

        try {
            $submission = DeviantArt::submission($event->result_favme);
        } catch (ImageProviderException) {
            abort(502, 'DeviantArt could not be reached');
        }
        abort_if($submission === null, 404, 'The submission could not be found');

        return response()->json([
            'id' => $submission->id,
            'title' => $submission->title,
            'author' => $submission->author,
            'previewUrl' => $submission->preview,
            'fullsizeUrl' => $submission->fullsize,
        ]);
    }

    /**
     * @OA\Post(
     *   path="/events",
     *   operationId="PostEvents",
     *   description="Disabled: answers 501 after the permission check. Requires staff",
     *   tags={"events"},
     *   @OA\Response(response="501", description="Creating events is currently not allowed", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     * @OA\Put(
     *   path="/events/{id}",
     *   operationId="PutEventsId",
     *   description="Disabled: answers 501 after the permission check. Requires staff",
     *   tags={"events"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="501", description="Editing events is currently not allowed", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     * @OA\Delete(
     *   path="/events/{id}",
     *   operationId="DeleteEventsId",
     *   description="Disabled: answers 501 after the permission check. Requires staff",
     *   tags={"events"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="501", description="Deleting events is currently not allowed", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     * @OA\Post(
     *   path="/events/{id}/finalize",
     *   operationId="PostEventsIdFinalize",
     *   description="Disabled: answers 501 after the permission check. Requires staff",
     *   tags={"events"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="501", description="Events cannot be finalized currently", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     * @OA\Post(
     *   path="/events/{id}/entries/check",
     *   operationId="PostEventsIdEntriesCheck",
     *   description="Disabled: answers 501 for signed-in users",
     *   tags={"events"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="501", description="Events cannot receive entries currently", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function disabled(Request $request): JsonResponse
    {
        $what = match (true) {
            $request->isMethod('POST') && str_ends_with($request->path(), 'finalize') => "Events can't be finalized currently.",
            str_ends_with($request->path(), 'check') => "Events can't receive entries currently.",
            $request->isMethod('POST') => 'Creating new events is currently not allowed.',
            $request->isMethod('PUT') => 'Editing existing events is currently not allowed.',
            default => 'Deleting events is currently not allowed.',
        };

        return response()->json(['message' => $what], 501);
    }
}
