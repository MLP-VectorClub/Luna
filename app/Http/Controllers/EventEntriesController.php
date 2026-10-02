<?php

namespace App\Http\Controllers;

use App\Exceptions\ImageProviderException;
use App\Models\Event;
use App\Models\EventEntry;
use App\Utils\ImageProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EventEntriesController extends Controller
{
    private const SUBMISSION_PROVIDERS = ['fav.me', 'dA', 'sta.sh'];

    /**
     * @OA\Schema(
     *   schema="EventEntry",
     *   type="object",
     *   description="A submission made to a community event",
     *   required={"link", "title", "prevSrc"},
     *   @OA\Property(property="link", type="string", format="uri", description="URL of the submitted deviation or Sta.sh submission"),
     *   @OA\Property(property="title", type="string", minLength=2, maxLength=64),
     *   @OA\Property(property="prevSrc", type="string", format="uri", nullable=true, description="URL of the custom preview image, if one was provided")
     * )
     * @OA\Get(
     *   path="/event-entries/{entryid}",
     *   operationId="GetEventEntriesEntryid",
     *   description="Get an entry's details for management purposes. Requires the entry to belong to the current user, or staff permissions.",
     *   tags={"events"},
     *   @OA\Parameter(in="path", name="entryid", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/EventEntry")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Not the owner of the entry, or the event has ended", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Entry not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, int $entryid): JsonResponse
    {
        $entry = $this->manageable($request, $entryid);

        return response()->json($this->present($entry));
    }

    /**
     * @OA\Put(
     *   path="/event-entries/{entryid}",
     *   operationId="PutEventEntriesEntryid",
     *   description="Update an existing entry. Requires the entry to belong to the current user (or staff permissions), and the event must not have ended (unless staff).",
     *   tags={"events"},
     *   @OA\Parameter(in="path", name="entryid", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"link", "title"},
     *     @OA\Property(property="link", type="string", format="uri", description="URL of a deviation or Sta.sh submission"),
     *     @OA\Property(property="title", type="string", minLength=2, maxLength=64),
     *     @OA\Property(property="prevSrc", type="string", format="uri", nullable=true, description="Optional custom preview image URL")
     *   )),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false)),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Not the owner of the entry, or the event has ended", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Entry not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(Request $request, int $entryid): JsonResponse
    {
        $entry = $this->manageable($request, $entryid);

        $link = $request->input('link');
        if (!is_string($link) || trim($link) === '') {
            $this->fail('link', 'Entry link is missing');
        }
        try {
            $submission = ImageProvider::resolve($link, self::SUBMISSION_PROVIDERS, false);
        } catch (ImageProviderException $e) {
            $this->fail('link', $e->actualProvider !== null || str_contains($e->getMessage(), 'not from a supported provider')
                ? 'Entry link must point to a deviation or Sta.sh submission'
                : 'Error while checking submission link: '.$e->getMessage());
        }

        $title = $request->input('title');
        if (!is_string($title) || trim($title) === '') {
            $this->fail('title', 'Entry title is missing');
        }
        $title = trim($title);
        if (mb_strlen($title) < 2 || mb_strlen($title) > 64) {
            $this->fail('title', 'Entry title must be between 2 and 64 characters long');
        }
        if (!preg_match('/^[ -~]+$/', $title)) {
            $this->fail('title', 'Entry title contains invalid characters');
        }

        $prev = ['prev_src' => null, 'prev_full' => null, 'prev_thumb' => null];
        $prev_src = $request->input('prevSrc');
        if (is_string($prev_src) && trim($prev_src) !== '') {
            try {
                $preview = ImageProvider::resolve(trim($prev_src));
            } catch (ImageProviderException $e) {
                $this->fail('prevSrc', 'Preview image error: '.$e->getMessage());
            }
            $prev = ['prev_src' => trim($prev_src), 'prev_full' => $preview->fullsize, 'prev_thumb' => $preview->preview];
        }

        $entry->fill(['sub_id' => $submission->id, 'sub_prov' => $submission->provider, 'title' => $title] + $prev)->save();

        return response()->json(new \stdClass());
    }

    /**
     * @OA\Delete(
     *   path="/event-entries/{entryid}",
     *   operationId="DeleteEventEntriesEntryid",
     *   description="Delete an existing entry. Requires the entry to belong to the current user (or staff permissions), and the event must not have ended (unless staff).",
     *   tags={"events"},
     *   @OA\Parameter(in="path", name="entryid", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="204", description="Deleted"),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Not the owner of the entry, or the event has ended", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Entry not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, int $entryid): Response
    {
        $this->manageable($request, $entryid)->delete();

        return response()->noContent();
    }

    /**
     * @OA\Get(
     *   path="/events/{id}/entries",
     *   operationId="GetEventsIdEntries",
     *   description="Placeholder, same as in Winterchilla: the route expects an entry id and answers 404 for any signed in user. Use /event-entries/{entryid}",
     *   tags={"events"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Entry ID is missing or invalid", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     * @OA\Put(
     *   path="/events/{id}/entries",
     *   operationId="PutEventsIdEntries",
     *   description="Placeholder, same as in Winterchilla: answers 404 for any signed in user. Use /event-entries/{entryid}",
     *   tags={"events"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Entry ID is missing or invalid", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     * @OA\Delete(
     *   path="/events/{id}/entries",
     *   operationId="DeleteEventsIdEntries",
     *   description="Placeholder, same as in Winterchilla: answers 404 for any signed in user. Use /event-entries/{entryid}",
     *   tags={"events"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Entry ID is missing or invalid", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function placeholder(): never
    {
        throw new HttpException(404, 'Entry ID is missing or invalid');
    }

    private function manageable(Request $request, int $entryid): EventEntry
    {
        $entry = EventEntry::find($entryid);
        if ($entry === null) {
            throw new HttpException(404, 'The requested entry could not be found');
        }
        $user = $request->user();
        if ($entry->submitted_by !== $user->id && !$user->isStaff()) {
            throw new AuthorizationException("You don't have permission to manage this entry");
        }
        $event = Event::findOrFail($entry->event_id);
        if (!$user->isStaff() && $event->ends_at->getTimestamp() < time()) {
            throw new AuthorizationException('This event has ended, entries can no longer be submitted or modified. Please ask a staff member if you need to make any changes.');
        }

        return $entry;
    }

    private function present(EventEntry $entry): array
    {
        $link = match ($entry->sub_prov) {
            'sta.sh' => "http://sta.sh/{$entry->sub_id}",
            'dA' => "https://www.deviantart.com/art/{$entry->sub_id}",
            default => "http://{$entry->sub_prov}/{$entry->sub_id}",
        };

        return ['link' => $link, 'title' => $entry->title, 'prevSrc' => $entry->prev_src];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
