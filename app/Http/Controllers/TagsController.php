<?php

namespace App\Http\Controllers;

use App\Enums\TagType;
use App\Models\Appearance;
use App\Models\Tag;
use App\Models\TagChange;
use App\Utils\AppearanceIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use OpenApi\Annotations as OA;

class TagsController extends Controller
{
    /**
     * @OA\Schema(
     *   schema="Tag",
     *   description="Represents a color guide tag",
     *   type="object",
     *   required={"id", "name", "title", "type", "uses", "synonymOf"},
     *   additionalProperties=false,
     *   @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *   @OA\Property(property="name", type="string"),
     *   @OA\Property(property="title", type="string", nullable=true, description="Optional human-friendly title for the tag"),
     *   @OA\Property(property="type", type="string", nullable=true, description="The tag's type/category"),
     *   @OA\Property(property="uses", type="integer", minimum=0, description="Number of appearances this tag is applied to"),
     *   @OA\Property(property="synonymOf", type="integer", nullable=true, description="ID of the tag this one is a synonym of, if any")
     * )
     * @OA\Schema(
     *   schema="TagListItem",
     *   type="object",
     *   required={"id", "name", "type", "title", "uses", "synonymOf"},
     *   additionalProperties=false,
     *   @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *   @OA\Property(property="name", type="string"),
     *   @OA\Property(property="type", type="string", nullable=true, enum={"app", "cat", "gen", "spec", "char", "warn", null}),
     *   @OA\Property(property="title", type="string", nullable=true),
     *   @OA\Property(property="uses", type="integer", minimum=0),
     *   @OA\Property(property="synonymOf", type="object", nullable=true, required={"id", "name"},
     *     @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *     @OA\Property(property="name", type="string")
     *   )
     * )
     * @OA\Schema(
     *   schema="TagInput",
     *   type="object",
     *   required={"name"},
     *   @OA\Property(property="name", type="string", description="Tag name"),
     *   @OA\Property(property="type", type="string", description="Tag type/category"),
     *   @OA\Property(property="title", type="string", nullable=true, maxLength=255, description="Optional human-friendly title"),
     *   @OA\Property(property="addTo", type="integer", description="Only when creating: appearance to add the new tag to")
     * )
     * @OA\Get(
     *   path="/tags",
     *   operationId="GetTags",
     *   description="List tags, ordered by type and name",
     *   tags={"tags"},
     *   security={},
     *   @OA\Parameter(in="query", name="page", @OA\Schema(type="integer", minimum=1, default=1)),
     *   @OA\Parameter(in="query", name="size", @OA\Schema(type="integer", minimum=1, maximum=100, default=50)),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(
     *     type="object", required={"tags", "pagination", "canEdit"}, additionalProperties=false,
     *     @OA\Property(property="tags", type="array", @OA\Items(ref="#/components/schemas/TagListItem")),
     *     @OA\Property(property="pagination", ref="#/components/schemas/Pagination"),
     *     @OA\Property(property="canEdit", type="boolean", description="Whether the current user may edit tags")
     *   )),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $valid = Validator::make($request->query(), [
            'page' => 'sometimes|integer|min:1',
            'size' => 'sometimes|integer|between:1,100',
        ])->validate();
        $size = (int) ($valid['size'] ?? 50);

        $pagination = Tag::with('synonymTarget')
            ->orderByRaw('CASE WHEN type IS NULL THEN 1 ELSE 0 END')
            ->orderBy('type')
            ->orderBy('name')
            ->paginate($size, page: (int) ($valid['page'] ?? 1));

        return response()->json([
            'tags' => $pagination->getCollection()->map(fn(Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'type' => $tag->type,
                'title' => $tag->title,
                'uses' => $tag->uses,
                'synonymOf' => $tag->synonymTarget ? ['id' => $tag->synonymTarget->id, 'name' => $tag->synonymTarget->name] : null,
            ])->values(),
            'pagination' => [
                'currentPage' => $pagination->currentPage(),
                'totalPages' => max(1, $pagination->lastPage()),
                'totalItems' => $pagination->total(),
                'itemsPerPage' => $size,
            ],
            'canEdit' => $request->user()?->isStaff() ?? false,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/tags/{id}",
     *   operationId="GetTagsId",
     *   description="Get a tag. Staff only",
     *   tags={"tags"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/Tag")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(int $id): JsonResponse
    {
        return response()->json(self::resource(Tag::findOrFail($id)));
    }

    /**
     * @OA\Post(
     *   path="/tags",
     *   operationId="PostTags",
     *   description="Create a tag, optionally adding it to an appearance right away. Staff only",
     *   tags={"tags"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/TagInput")),
     *   @OA\Response(response="201", description="Created", @OA\JsonContent(
     *     allOf={@OA\Schema(ref="#/components/schemas/Tag"), @OA\Schema(type="object", @OA\Property(property="warning", type="string", description="Present when the tag could not be added to the requested appearance"))}
     *   )),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function create(Request $request): JsonResponse
    {
        $tag = new Tag();
        $tag->fill($this->validated($request, null));
        $tag->save();
        $created = self::resource($tag->fresh());

        if ($request->filled('addTo') || $request->input('addTo') === '0' || $request->input('addTo') === 0) {
            $appearance_id = (int) $request->input('addTo');
            if ($appearance_id === 0) {
                return response()->json($created + ['warning' => "The tag was created, but it could not be added to the appearance because it can't be tagged."], 201);
            }

            $appearance = Appearance::find($appearance_id);
            if ($appearance === null) {
                return response()->json($created + ['warning' => "The tag was created, but it could not be added to the appearance (#$appearance_id) because it doesn't seem to exist. Please try adding the tag manually."], 201);
            }

            $this->addTo($appearance, $tag, $request->user()->id);
            $created = self::resource($tag->fresh());
        }

        return response()->json($created, 201);
    }

    /**
     * @OA\Put(
     *   path="/tags/{id}",
     *   operationId="PutTagsId",
     *   description="Update a tag. Staff only",
     *   tags={"tags"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/TagInput")),
     *   @OA\Response(response="200", description="Updated", @OA\JsonContent(ref="#/components/schemas/Tag")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tag = Tag::findOrFail($id);
        $tag->update($this->validated($request, $tag));
        $tag->appearances->each(fn(Appearance $appearance) => AppearanceIndex::update($appearance));

        return response()->json(self::resource($tag->fresh()));
    }

    /**
     * @OA\Delete(
     *   path="/tags/{id}",
     *   operationId="DeleteTagsId",
     *   description="Delete a tag. When the tag is in use the request has to be repeated with `sanityCheck` set. Staff only",
     *   tags={"tags"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="query", name="sanityCheck", description="Confirms deleting a tag that is in use", @OA\Schema(type="boolean")),
     *   @OA\Response(response="204", description="Deleted"),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="The tag is in use", @OA\JsonContent(
     *     type="object", required={"message", "uses"}, @OA\Property(property="message", type="string"), @OA\Property(property="uses", type="integer", description="Number of appearances the tag is used on")
     *   ))
     * )
     */
    public function destroy(Request $request, int $id)
    {
        $tag = Tag::findOrFail($id);
        $source = $tag->synonym_of !== null ? Tag::find($tag->synonym_of) : $tag;
        $appearances = $source->appearances()->get();
        $uses = $appearances->count();
        if (!$request->has('sanityCheck') && $uses > 0) {
            return response()->json([
                'message' => "This tag is currently used on $uses ".($uses === 1 ? 'appearance' : 'appearances').'. Deleting will permanently remove the tag from those appearances. Repeat the request with sanityCheck set to confirm.',
                'uses' => $uses,
            ], 409);
        }

        $tag->delete();
        $appearances->each(fn(Appearance $appearance) => AppearanceIndex::update($appearance));

        return response()->noContent();
    }

    /**
     * @OA\Post(
     *   path="/tags/recount-uses",
     *   operationId="PostTagsRecountUses",
     *   description="Recount how many appearances each of the given tags is used on. Staff only",
     *   tags={"tags"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"tagIds"},
     *     @OA\Property(property="tagIds", type="array", description="IDs of tags to recount", @OA\Items(ref="#/components/schemas/OneBasedId"))
     *   )),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false,
     *     @OA\Property(property="message", type="string"),
     *     @OA\Property(property="counts", type="object", description="Map of tag ID to its new use count")
     *   )),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function recountUses(Request $request): JsonResponse
    {
        $ids = $request->input('tagIds');
        if (is_string($ids)) {
            $ids = $ids === '' ? [] : explode(',', $ids);
        }
        $valid = Validator::make(['tagIds' => $ids], [
            'tagIds' => ['required', 'array', 'min:1', function ($attribute, $value, $fail) {
                foreach ($value as $id) {
                    if (!is_numeric($id) || (int) $id < 1) {
                        $fail('List of tags is invalid');
                        return;
                    }
                }
            }],
        ], ['tagIds.required' => 'Missing list of tags to update', 'tagIds.min' => 'Missing list of tags to update'])->validate();

        $counts = [];
        $updates = 0;
        foreach (Tag::whereIn('id', array_map('intval', $valid['tagIds']))->get() as $tag) {
            $old = $tag->uses;
            $counts[$tag->id] = $tag->updateUses();
            if ($old !== $counts[$tag->id]) {
                $updates++;
            }
        }

        return response()->json([
            'message' => $updates === 0
                ? 'There was no change in the tag usage counts'
                : "$updates tag".($updates !== 1 ? "s'" : "'s").' use count'.($updates !== 1 ? 's were' : ' was').' updated',
            'counts' => (object) $counts,
        ]);
    }

    /**
     * @OA\Put(
     *   path="/tags/{id}/synonym",
     *   operationId="PutTagsIdSynonym",
     *   description="Make the tag a synonym of another one, moving its uses to the target. Staff only",
     *   tags={"tags"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"targetId"}, @OA\Property(property="targetId", ref="#/components/schemas/OneBasedId"))),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false, @OA\Property(property="target", ref="#/components/schemas/Tag"))),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="Either tag is already a synonym", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function makeSynonym(Request $request, int $id): JsonResponse
    {
        $tag = Tag::with('synonymTarget')->findOrFail($id);
        if ($tag->synonym_of !== null) {
            return response()->json(['message' => "The selected tag is already a synonym of the \"{$tag->synonymTarget->name}\" tag"], 409);
        }

        $valid = Validator::make($request->all(), ['targetId' => ['required', 'integer', 'min:1']], [
            'targetId.required' => 'Target tag ID is missing',
            'targetId.integer' => 'Target tag ID is invalid',
        ])->validate();
        $target = Tag::with('synonymTarget')->find($valid['targetId']);
        if ($target === null) {
            return response()->json(['message' => 'Target tag does not exist', 'errors' => ['targetId' => ['Target tag does not exist']]], 422);
        }
        if ($target->synonym_of !== null) {
            return response()->json(['message' => "The target tag is already a synonym of the \"{$target->synonymTarget->name}\" tag"], 409);
        }

        $affected = DB::transaction(function () use ($tag, $target) {
            $already_tagged = $target->appearances()->pluck('appearances.id')->all();
            $moving = $tag->appearances()->pluck('appearances.id')->all();
            $target->appearances()->attach(array_values(array_diff($moving, $already_tagged)));
            $tag->appearances()->detach();
            $tag->update(['synonym_of' => $target->id, 'uses' => 0]);
            $target->updateUses();

            return Appearance::whereIn('id', array_unique([...$moving, ...$already_tagged]))->get();
        });
        $affected->each(fn(Appearance $appearance) => AppearanceIndex::update($appearance));

        return response()->json(['message' => 'Tag synonyms created', 'target' => self::resource($target->fresh())]);
    }

    /**
     * @OA\Delete(
     *   path="/tags/{id}/synonym",
     *   operationId="DeleteTagsIdSynonym",
     *   description="Stop treating the tag as a synonym. Answers 204 when it was not a synonym. Staff only",
     *   tags={"tags"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="query", name="keepTagged", description="Apply the tag to the appearances its former target is on", @OA\Schema(type="boolean")),
     *   @OA\Response(response="200", description="The synonym was removed", @OA\JsonContent(type="object", additionalProperties=false, @OA\Property(property="keepTagged", type="boolean"))),
     *   @OA\Response(response="204", description="The tag was not a synonym"),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function removeSynonym(Request $request, int $id)
    {
        $tag = Tag::with('synonymTarget')->findOrFail($id);
        if ($tag->synonym_of === null) {
            return response()->noContent();
        }

        $keep_tagged = $request->has('keepTagged');
        DB::transaction(function () use ($tag, $keep_tagged) {
            if ($keep_tagged && $tag->synonymTarget) {
                $tag->appearances()->syncWithoutDetaching($tag->synonymTarget->appearances()->pluck('appearances.id')->all());
            }
            $tag->update(['synonym_of' => null]);
            $tag->updateUses();
        });
        $tag->appearances()->get()->each(fn(Appearance $appearance) => AppearanceIndex::update($appearance));

        return response()->json(['keepTagged' => $keep_tagged]);
    }

    private function addTo(Appearance $appearance, Tag $tag, int $user_id): void
    {
        $appearance->tags()->syncWithoutDetaching([$tag->id]);
        TagChange::create(['tag_id' => $tag->id, 'appearance_id' => $appearance->id, 'user_id' => $user_id, 'added' => true, 'tag_name' => $tag->name]);
        $tag->updateUses();
        AppearanceIndex::update($appearance);
    }

    private static function resource(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'title' => $tag->title,
            'type' => $tag->type,
            'uses' => $tag->uses,
            'synonymOf' => $tag->synonym_of,
        ];
    }

    /**
     * @return array{name: string, type?: string, title: ?string}
     */
    private function validated(Request $request, ?Tag $existing): array
    {
        $input = $request->all();
        $valid = Validator::make($input, [
            'name' => ['bail', 'required', 'string', 'between:2,64', function ($attribute, $value, $fail) {
                if (str_contains($value, ',')) {
                    $fail("Tag name ($value) cannot contain commas");
                } elseif ($value[0] === '-') {
                    $fail("Tag name ($value) cannot start with a dash");
                } elseif (preg_match_all('/[^a-z\d ().\-\']/u', strtolower($value), $matches)) {
                    $invalid = implode(', ', array_unique($matches[0]));
                    $fail("Tag name ($value) contains invalid characters: $invalid");
                }
            }],
            'type' => ['sometimes', 'nullable', Rule::in(array_map(fn(TagType $type) => $type->value, TagType::cases()))],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [
            'name.required' => 'Tag name cannot be empty',
            'name.between' => 'Tag name must be between 2 and 64 characters',
            'type.in' => 'Invalid tag type: '.($input['type'] ?? ''),
            'title.max' => 'Tag title cannot be longer than 255 characters',
        ])->validate();

        $data = ['name' => strtolower($valid['name'])];
        $type = $valid['type'] ?? null;
        if ($type !== null && $type !== '') {
            $data['type'] = $type;
        }

        $duplicate = Tag::where('name', $data['name'])
            ->when(isset($data['type']), fn($query) => $query->where('type', $data['type']), fn($query) => $query->whereNull('type'))
            ->when($existing, fn($query) => $query->where('id', '!=', $existing->id))
            ->exists();
        if ($duplicate) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'A tag with the same name and type already exists']);
        }

        $data['title'] = $valid['title'] ?? null;

        return $data;
    }
}
