<?php

namespace App\Http\Controllers;

use App\Models\Appearance;
use App\Models\PinnedAppearance;
use App\Models\Show;
use App\Models\ShowVote;
use App\Utils\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShowManagementController extends Controller
{
    /**
     * @OA\Schema(
     *   schema="Show",
     *   description="Represents a show entry (episode or movie/special)",
     *   type="object",
     *   required={"id", "type", "season", "episode", "parts", "no", "title", "airs", "notes", "createdAt", "updatedAt", "score", "postedBy"},
     *   additionalProperties=false,
     *   @OA\Property(property="id", type="integer", example=1),
     *   @OA\Property(property="type", type="string", enum={"episode", "movie", "short", "special"}),
     *   @OA\Property(property="season", type="integer", nullable=true),
     *   @OA\Property(property="episode", type="integer", nullable=true),
     *   @OA\Property(property="parts", type="integer", description="Number of parts the episode is split into (1 or 2)"),
     *   @OA\Property(property="no", type="integer", nullable=true, description="Overall number"),
     *   @OA\Property(property="title", type="string"),
     *   @OA\Property(property="airs", type="string", format="date-time"),
     *   @OA\Property(property="notes", type="string", nullable=true),
     *   @OA\Property(property="score", type="number", nullable=true, description="Average rating"),
     *   @OA\Property(property="createdAt", type="string", format="date-time"),
     *   @OA\Property(property="updatedAt", type="string", format="date-time", nullable=true),
     *   @OA\Property(property="postedBy", type="integer", description="ID of the user who created this show entry")
     * )
     * @OA\Schema(
     *   schema="ShowInput",
     *   type="object",
     *   required={"title", "airs"},
     *   @OA\Property(property="type", type="string", description="Required when creating. Cannot be changed to episode via update"),
     *   @OA\Property(property="season", type="integer", description="Required for episodes"),
     *   @OA\Property(property="episode", type="integer", description="Required for episodes"),
     *   @OA\Property(property="twoparter", type="boolean", description="Marks the episode as a two-parter"),
     *   @OA\Property(property="no", type="integer", description="Overall number"),
     *   @OA\Property(property="title", type="string", minLength=5, maxLength=100),
     *   @OA\Property(property="airs", type="string", description="Air date and time"),
     *   @OA\Property(property="notes", type="string", nullable=true, maxLength=1000)
     * )
     * @OA\Get(
     *   path="/show/{id}",
     *   operationId="GetShowId",
     *   description="Get a show entry with its state, the visitor's permissions and related appearances",
     *   tags={"shows"},
     *   security={},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"show"}, @OA\Property(property="show", allOf={
     *     @OA\Schema(ref="#/components/schemas/Show"),
     *     @OA\Schema(type="object", required={"aired", "willAir", "canEdit", "relatedAppearances"},
     *       @OA\Property(property="aired", type="boolean", description="Whether the show has already aired"),
     *       @OA\Property(property="willAir", type="string", format="date-time", description="When the show will have aired (air time plus its running time)"),
     *       @OA\Property(property="canEdit", type="boolean", description="Whether the current user may edit the show"),
     *       @OA\Property(property="relatedAppearances", type="array", @OA\Items(ref="#/components/schemas/PreviewAppearance"))
     *     )
     *   }))),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $show = Show::findOrFail($id);
        $is_staff = $request->user()?->isStaff() ?? false;

        return response()->json(['show' => self::resource($show) + [
            'aired' => $show->hasAired(),
            'willAir' => $show->willHaveAiredBy()->toIso8601String(),
            'canEdit' => $is_staff,
            'relatedAppearances' => $show->appearances()->orderBy('appearances.id')->get()
                ->filter(fn(Appearance $a) => $a->owner_id === null || $is_staff)
                ->map(fn(Appearance $a) => \App\Utils\ColorGuideHelper::mapPreviewAppearance($a))
                ->values()->all(),
        ]]);
    }

    /**
     * @OA\Get(
     *   path="/show/latest",
     *   operationId="GetShowLatest",
     *   description="The latest episode that aired or is about to",
     *   tags={"shows"},
     *   security={},
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/ShowListItem")),
     *   @OA\Response(response="404", description="Nothing has aired yet", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function latest(): JsonResponse
    {
        $latest = Show::whereNotNull('season')->where('airs', '<', now()->addDay())->orderByDesc('airs')->first();
        if ($latest === null) {
            throw new HttpException(404, 'Nothing has aired yet');
        }

        return response()->camelJson(ShowController::mapShowListItem($latest));
    }

    /**
     * @OA\Get(
     *   path="/show/next",
     *   operationId="GetShowNext",
     *   description="The next upcoming episode",
     *   tags={"shows"},
     *   security={},
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false,
     *     @OA\Property(property="episode", type="integer"), @OA\Property(property="airs", type="string", format="date-time"),
     *     @OA\Property(property="season", type="integer"), @OA\Property(property="title", type="string")
     *   )),
     *   @OA\Response(response="404", description="The show is on hiatus", @OA\JsonContent(type="object", @OA\Property(property="message", type="string"), @OA\Property(property="hiatus", type="boolean")))
     * )
     */
    public function next(): JsonResponse
    {
        $next = Show::whereNotNull('season')->where('airs', '>', now())->orderBy('airs')->first();
        if ($next === null) {
            return response()->json(['message' => "The show is on hiatus, the next episode's title and air date is unknown.", 'hiatus' => true], 404);
        }

        return response()->json([
            'episode' => $next->episode,
            'airs' => $next->airs->toIso8601String(),
            'season' => $next->season,
            'title' => $next->title,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/show/prefill",
     *   operationId="GetShowPrefill",
     *   description="Suggested values for the next episode to add. Staff only",
     *   tags={"shows"},
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"season", "episode", "no", "airday"},
     *     @OA\Property(property="season", type="integer"), @OA\Property(property="episode", type="integer"),
     *     @OA\Property(property="no", type="integer", description="Suggested overall number"),
     *     @OA\Property(property="airday", type="string", format="date", description="Suggested air date")
     *   )),
     *   @OA\Response(response="404", description="No last added episode found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function prefill(): JsonResponse
    {
        $last = Show::whereNotNull('season')->orderByDesc('no')->first();
        if ($last === null) {
            throw new HttpException(404, 'No last added episode found');
        }

        if ($last->parts === 2 && $last->episode + 1 === 26) {
            $season = $last->season + 1;
            $episode = 1;
            $airs = now()->next('Saturday')->format('Y-m-d');
        } else {
            $season = $last->season;
            $episode = min($last->episode + 1, 26);
            $airs = $last->airs->copy()->addWeek()->format('Y-m-d');
        }

        return response()->json(['season' => $season, 'episode' => $episode, 'no' => ($last->no ?? 0) + $last->parts, 'airday' => $airs]);
    }

    /**
     * @OA\Post(
     *   path="/show",
     *   operationId="PostShow",
     *   description="Create a show entry. Staff only",
     *   tags={"shows"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/ShowInput")),
     *   @OA\Response(response="201", description="Created", @OA\JsonContent(type="object", required={"id"}, additionalProperties=false, @OA\Property(property="id", ref="#/components/schemas/OneBasedId"))),
     *   @OA\Response(response="409", description="An episode with the same season and episode number exists", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function create(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $data['posted_by'] = $request->user()->id;
        $show = Show::create($data);

        return response()->json(['id' => $show->id], 201);
    }

    /**
     * @OA\Put(
     *   path="/show/{id}",
     *   operationId="PutShowId",
     *   description="Update a show entry. Staff only",
     *   tags={"shows"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/ShowInput")),
     *   @OA\Response(response="204", description="Updated"),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="Another episode has the same season and episode number", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(Request $request, int $id)
    {
        $show = Show::findOrFail($id);
        $show->update($this->validated($request, $show));

        return response()->noContent();
    }

    /**
     * @OA\Delete(
     *   path="/show/{id}",
     *   operationId="DeleteShowId",
     *   description="Delete a show entry. Staff only",
     *   tags={"shows"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="Deleted"),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        Show::findOrFail($id)->delete();

        return response()->json(new \stdClass());
    }

    /**
     * @OA\Get(
     *   path="/show/{id}/vote",
     *   operationId="GetShowIdVote",
     *   description="How many votes each rating (1-5) received",
     *   tags={"shows"},
     *   security={},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"data"}, @OA\Property(property="data", type="object", description="Map of vote values (1-5) to the number of votes received", additionalProperties=@OA\AdditionalProperties(type="integer")))),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function votes(int $id): JsonResponse
    {
        return response()->json(['data' => (object) $this->voteCounts(Show::findOrFail($id))]);
    }

    /**
     * @OA\Post(
     *   path="/show/{id}/vote",
     *   operationId="PostShowIdVote",
     *   description="Rate a show that has aired. Everybody can vote once",
     *   tags={"shows"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"vote"}, @OA\Property(property="vote", type="integer", minimum=1, maximum=5))),
     *   @OA\Response(response="200", description="The vote counts after voting", @OA\JsonContent(type="object", required={"data"}, @OA\Property(property="data", type="object", additionalProperties=@OA\AdditionalProperties(type="integer")))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="The show has not aired yet, or the user already voted", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function vote(Request $request, int $id): JsonResponse
    {
        $show = Show::findOrFail($id);
        if (!$show->hasAired()) {
            throw new HttpException(409, 'You can only vote on this episode after it has aired.');
        }
        $user = $request->user();
        if (ShowVote::where('show_id', $show->id)->where('user_id', $user->id)->exists()) {
            throw new HttpException(409, "You already voted for this {$show->type}");
        }

        $valid = Validator::make($request->all(), ['vote' => ['required', 'integer', 'between:1,5']], [
            'vote.required' => 'Vote value missing from request',
            'vote.between' => 'Vote value must be an integer between 1 and 5 (inclusive)',
        ])->validate();

        ShowVote::create(['show_id' => $show->id, 'user_id' => $user->id, 'vote' => $valid['vote']]);
        $show->updateScore();

        return response()->json(['data' => (object) $this->voteCounts($show)]);
    }

    /**
     * @OA\Get(
     *   path="/show/{id}/appearances",
     *   operationId="GetShowIdAppearances",
     *   description="Official appearances that can be linked to the show, and which ones are. Staff only",
     *   tags={"shows"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"groups", "entries", "linkedIds"},
     *     @OA\Property(property="groups", type="object", description="Map of guide names to their display labels"),
     *     @OA\Property(property="entries", type="array", description="Appearances eligible to be linked, excluding pinned and personal ones", @OA\Items(type="object", @OA\Property(property="id", ref="#/components/schemas/OneBasedId"), @OA\Property(property="label", type="string"), @OA\Property(property="guide", type="string", nullable=true))),
     *     @OA\Property(property="linkedIds", type="array", @OA\Items(ref="#/components/schemas/OneBasedId"))
     *   )),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function appearances(int $id): JsonResponse
    {
        $show = Show::findOrFail($id);

        return response()->json([
            'groups' => ['pony' => 'Friendship is Magic', 'eqg' => 'Equestria Girls'],
            'entries' => Appearance::whereNull('owner_id')->whereNotIn('id', PinnedAppearance::pluck('appearance_id'))->orderBy('label')->get(['id', 'label', 'guide'])
                ->map(fn(Appearance $a) => ['id' => $a->id, 'label' => $a->label, 'guide' => $a->guide?->value])->values(),
            'linkedIds' => $show->appearances()->pluck('appearances.id')->values(),
        ]);
    }

    /**
     * @OA\Put(
     *   path="/show/{id}/appearances",
     *   operationId="PutShowIdAppearances",
     *   description="Replace the appearances linked to the show. Staff only",
     *   tags={"shows"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(@OA\JsonContent(type="object", @OA\Property(property="ids", type="array", description="Appearance IDs that should be linked", @OA\Items(ref="#/components/schemas/OneBasedId")))),
     *   @OA\Response(response="200", description="Saved"),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function setAppearances(Request $request, int $id): JsonResponse
    {
        $show = Show::findOrFail($id);
        $ids = $request->input('ids');
        if (is_string($ids)) {
            $ids = $ids === '' ? [] : explode(',', $ids);
        }
        $ids ??= [];
        if (!is_array($ids) || collect($ids)->contains(fn($value) => !is_numeric($value) || (int) $value < 1)) {
            throw ValidationException::withMessages(['ids' => 'Appearance ID list is invalid']);
        }
        $show->appearances()->sync(Appearance::whereIn('id', array_map('intval', $ids))->pluck('id')->all());

        return response()->json(new \stdClass());
    }

    private function voteCounts(Show $show): array
    {
        return $show->votes()->selectRaw('vote, count(*) as total')->groupBy('vote')->orderBy('vote')->pluck('total', 'vote')
            ->map(fn($total) => (int) $total)->all();
    }

    private static function resource(Show $show): array
    {
        return [
            'id' => $show->id,
            'type' => $show->type,
            'season' => $show->season,
            'episode' => $show->episode,
            'parts' => $show->parts,
            'no' => $show->no,
            'title' => $show->title,
            'airs' => $show->airs?->toIso8601String(),
            'notes' => $show->notes,
            'score' => $show->score === null ? null : (float) $show->score,
            'createdAt' => $show->created_at?->toIso8601String(),
            'updatedAt' => $show->updated_at?->toIso8601String(),
            'postedBy' => $show->posted_by,
        ];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Show $existing): array
    {
        $input = $request->all();
        $update = [];

        $types = array_keys(ConfigController::SHOW_TYPE_LABELS);
        if ($existing === null) {
            $valid_type = Validator::make($input, ['type' => ['required', 'in:'.implode(',', $types)]], [
                'type.required' => 'Show type is missing',
                'type.in' => 'Show type ('.($input['type'] ?? '').') is invalid',
            ])->validate();
            $update['type'] = $valid_type['type'];
            $is_episode = $update['type'] === 'episode';
        } else {
            $is_episode = $existing->isEpisode();
        }
        $what = ucfirst($update['type'] ?? $existing->type);

        if ($is_episode) {
            $valid = Validator::make($input, [
                'season' => ['required', 'integer', 'between:0,9'],
                'episode' => ['required', 'integer', 'between:1,26'],
            ], [
                'season.required' => 'Season number is missing',
                'season.integer' => 'Season number is invalid',
                'season.between' => 'Season number must be between 0 and 9',
                'episode.required' => 'Episode number is missing',
                'episode.integer' => 'Episode number is invalid',
                'episode.between' => 'Episode number must be between 1 and 26',
            ])->validate();
            $update['season'] = (int) $valid['season'];
            $update['episode'] = (int) $valid['episode'];

            $changed = $existing === null || $update['season'] !== $existing->season || $update['episode'] !== $existing->episode;
            if ($changed && Show::where('season', $update['season'])->where('episode', $update['episode'])->exists()) {
                throw new HttpException(409, "There's already an episode with the same season & episode number");
            }

            $update['parts'] = 1;
            if ($request->boolean('twoparter')) {
                if (Show::where('season', $update['season'])->where('episode', $update['episode'] + 1)->exists()) {
                    throw new HttpException(409, "This episode cannot have two parts because S{$update['season']}E".($update['episode'] + 1).' already exists.');
                }
                $update['parts'] = 2;
            }
        } elseif ($existing !== null && $request->has('type')) {
            if (!in_array($input['type'], $types, true)) {
                $this->fail('type', "Show type ({$input['type']}) is invalid");
            }
            if ($input['type'] === 'episode') {
                $this->fail('type', 'Show entries cannot be converted to episodes via the interface.');
            }
            $update['type'] = $input['type'];
        }

        $valid = Validator::make($input, [
            'no' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'title' => ['required', 'string', 'between:5,100', 'regex:/^[ -~]+$/'],
            'airs' => ['required', 'string'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ], [
            'no.integer' => 'Overall number ('.($input['no'] ?? '').') is invalid',
            'no.min' => 'Overall number cannot be less than 1',
            'title.required' => "$what title is missing",
            'title.between' => "$what title must be between 5 and 100 characters",
            'title.regex' => "$what title contains invalid characters",
            'airs.required' => 'No air date & time specified',
            'notes.max' => "$what notes cannot be longer than 1000 characters",
        ])->validate();

        $update['no'] = isset($valid['no']) && $valid['no'] !== '' ? (int) $valid['no'] : null;
        $update['title'] = trim($valid['title']);

        $airs = strtotime($valid['airs']);
        if ($airs === false) {
            $this->fail('airs', "Invalid air date and/or time ({$valid['airs']}) specified");
        }
        if ($airs < strtotime('2010-10-10T00:00:00')) {
            $this->fail('airs', 'Air dates before October 10th, 2010 are invalid.');
        }
        $update['airs'] = date('c', strtotime('this minute', $airs));

        $notes = $valid['notes'] ?? null;
        $update['notes'] = $notes === null || $notes === '' ? null : HtmlSanitizer::sanitize($notes, ['a'], ['a.href']);

        return $update;
    }
}
