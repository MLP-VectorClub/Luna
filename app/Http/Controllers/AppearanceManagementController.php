<?php

namespace App\Http\Controllers;

use App\Enums\GuideName;
use App\Enums\UserPrefKey;
use App\Models\Appearance;
use App\Models\Color;
use App\Models\ColorGroup;
use App\Models\PcgSlotHistory;
use App\Models\PinnedAppearance;
use App\Models\Show;
use App\Models\Tag;
use App\Models\TagChange;
use App\Models\User;
use App\Utils\AppearanceIndex;
use App\Utils\ColorGuideHelper;
use App\Utils\Core;
use App\Utils\ImageHelper;
use App\Utils\LogWriter;
use App\Utils\UserPrefHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The write side of the color guide appearances. Cutie marks, sprites and SVG sanitizing live elsewhere.
 */
class AppearanceManagementController extends Controller
{
    private const PONY_TEMPLATE = [
        'Coat' => ['Outline', 'Fill', 'Shadow Outline', 'Shadow Fill'],
        'Mane & Tail' => ['Outline', 'Fill'],
        'Iris' => ['Gradient Top', 'Gradient Middle', 'Gradient Bottom', 'Highlight Top', 'Highlight Bottom'],
        'Cutie Mark' => ['Fill 1', 'Fill 2'],
        'Magic' => ['Aura'],
    ];

    private const HUMAN_TEMPLATE = [
        'Skin' => ['Outline', 'Fill'],
        'Hair' => ['Outline', 'Fill'],
        'Eyes' => ['Gradient Top', 'Gradient Middle', 'Gradient Bottom', 'Highlight Top', 'Highlight Bottom', 'Eyebrows'],
    ];

    /**
     * @OA\Schema(
     *   schema="AppearanceInput",
     *   type="object",
     *   required={"label"},
     *   @OA\Property(property="guide", type="string", nullable=true, description="Guide identifier (staff only). When omitted, the appearance is created in the user's Personal Color Guide"),
     *   @OA\Property(property="label", type="string", minLength=2, maxLength=70),
     *   @OA\Property(property="notes", type="string", nullable=true, maxLength=1000),
     *   @OA\Property(property="private", type="boolean"),
     *   @OA\Property(property="template", type="boolean", description="Only when creating: apply the default color group template")
     * )
     * @OA\Get(
     *   path="/appearances/{id}/metadata",
     *   operationId="GetAppearancesIdMetadata",
     *   description="The editable fields of an appearance. Requires being the owner, or staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/PrivateAppearance")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function metadata(Request $request, int $id): JsonResponse
    {
        $appearance = $this->managed($request, $id);

        return response()->json([
            'label' => $appearance->label,
            'notes' => $appearance->notes_src,
            'private' => (bool) $appearance->private,
        ]);
    }

    /**
     * @OA\Post(
     *   path="/appearances",
     *   operationId="PostAppearances",
     *   description="Create an appearance. Staff can create them in the official guides, everybody else in their Personal Color Guide (needs free slots)",
     *   tags={"appearances"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/AppearanceInput")),
     *   @OA\Response(response="201", description="Created", @OA\JsonContent(type="object", additionalProperties=false,
     *     @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *     @OA\Property(property="info", type="string", description="Additional info, e.g. if applying the template failed")
     *   )),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="No free personal guide slots", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function create(Request $request): JsonResponse
    {
        $user = $request->user();
        $guide_input = $request->input('guide');
        $personal = $guide_input === null || $guide_input === '';
        if (!$personal && !$user->isStaff()) {
            throw new AuthorizationException("You don't have permission to add appearances to the official Color Guide");
        }
        if ($personal) {
            $this->checkPersonalCreation($user);
        }

        $data = $this->validated($request, null, $personal ? null : GuideName::tryFrom((string) $guide_input), $personal);
        if ($personal) {
            $data['owner_id'] = $user->id;
        } else {
            $data['order'] = (Appearance::where('guide', $data['guide'])->max('order') ?? 0) + 1;
        }

        $appearance = new Appearance($data);
        $appearance->save();
        $appearance = $appearance->fresh();
        AppearanceIndex::update($appearance);

        $response = ['id' => $appearance->id];
        $use_template = $request->boolean('template');
        if ($use_template) {
            try {
                $this->applyTemplateTo($appearance);
            } catch (\Throwable $e) {
                $response['info'] = 'The common color groups could not be added. Reason: '.$e->getMessage();
                $use_template = false;
            }
        }

        LogWriter::record('appearances', [
            'action' => 'add',
            'id' => $appearance->id,
            'order' => $appearance->order,
            'label' => $appearance->label,
            'notes' => $appearance->notes_src,
            'guide' => $appearance->guide?->value,
            'usetemplate' => $use_template,
            'private' => (bool) $appearance->private,
            'owner_id' => $appearance->owner_id,
        ]);

        if ($appearance->owner_id !== null) {
            PcgSlotHistory::record($appearance->owner_id, 'appearance_add', null, ['id' => $appearance->id, 'label' => $appearance->label]);
            $user->syncPcgSlotCount();
        }

        return response()->json($response, 201);
    }

    /**
     * @OA\Put(
     *   path="/appearances/{id}",
     *   operationId="PutAppearancesId",
     *   description="Update an appearance's name, notes and privacy. Requires being the owner, or staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/AppearanceInput")),
     *   @OA\Response(response="200", description="Updated", @OA\JsonContent(type="object", additionalProperties=false, @OA\Property(property="label", type="string"))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $appearance = $this->managed($request, $id);
        $old = $appearance->only(['label', 'notes_src', 'private', 'owner_id']);

        $data = $this->validated($request, $appearance, $appearance->guide, $appearance->owner_id !== null);
        unset($data['guide']);
        // Omitted notes/private are left unchanged, notes sent empty are cleared
        if (!$request->has('notes')) {
            unset($data['notes_src'], $data['notes_rend']);
        }
        if (!$request->has('private') || $request->input('private') === null) {
            unset($data['private']);
        }
        if (($data['private'] ?? false) === true) {
            $data['last_cleared'] = now();
        }
        $appearance->update($data);
        AppearanceIndex::update($appearance);

        $diff = [];
        foreach (['label' => 'label', 'notes_src' => 'notes', 'private' => 'private', 'owner_id' => 'owner_id'] as $column => $key) {
            if ($appearance->{$column} !== $old[$column]) {
                $diff["old$key"] = $old[$column];
                $diff["new$key"] = $appearance->{$column};
            }
        }
        if ($diff !== []) {
            LogWriter::record('appearance_modify', ['appearance_id' => $appearance->id, 'changes' => json_encode($diff)]);
        }

        return response()->json(['label' => $appearance->label]);
    }

    /**
     * @OA\Delete(
     *   path="/appearances/{id}",
     *   operationId="DeleteAppearancesId",
     *   description="Delete an appearance. Requires being the owner, or staff. Pinned appearances cannot be deleted",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\Response(response="204", description="Deleted"),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="The appearance is pinned", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, int $id): Response
    {
        $appearance = $this->managed($request, $id);
        if (PinnedAppearance::where('appearance_id', $appearance->id)->exists()) {
            throw new HttpException(409, "This appearance cannot be deleted because it's currently pinned");
        }

        $tags = $appearance->tags()->get();
        AppearanceIndex::remove($appearance);
        $appearance->delete();
        $tags->each(fn(Tag $tag) => $tag->updateUses());

        LogWriter::record('appearances', [
            'action' => 'del',
            'id' => $appearance->id,
            'order' => $appearance->order,
            'label' => $appearance->label,
            'notes' => $appearance->notes_src,
            'guide' => $appearance->guide?->value,
            'added' => $appearance->created_at?->toIso8601String(),
            'private' => (bool) $appearance->private,
            'owner_id' => $appearance->owner_id,
        ]);

        if ($appearance->owner_id !== null) {
            PcgSlotHistory::record($appearance->owner_id, 'appearance_del', null, ['id' => $appearance->id, 'label' => $appearance->label]);
            $appearance->owner?->syncPcgSlotCount();
        }

        return response()->noContent();
    }

    /**
     * @OA\Post(
     *   path="/appearances/{id}/template",
     *   operationId="PostAppearancesIdTemplate",
     *   description="Add the default color groups to an appearance that has none. Requires being the owner, or staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\Response(response="200", description="Applied"),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="The appearance already has color groups", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function template(Request $request, int $id): JsonResponse
    {
        $appearance = $this->managed($request, $id);
        try {
            $this->applyTemplateTo($appearance);
        } catch (\RuntimeException $e) {
            throw new HttpException(409, 'Applying the template failed. Reason: '.$e->getMessage());
        }

        return response()->json(new \stdClass());
    }

    /**
     * @OA\Delete(
     *   path="/appearances/{id}/contents",
     *   operationId="DeleteAppearancesIdContents",
     *   description="Selectively clear parts of an appearance. Requires being the owner, or staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\RequestBody(@OA\JsonContent(type="object",
     *     @OA\Property(property="wipeColors", type="string", enum={"color_hex", "color_all", "all"}, description="color_hex: clear hex values; color_all: delete all colors; all: delete all color groups"),
     *     @OA\Property(property="wipeTags", type="boolean", description="Remove all tags (official guide only)"),
     *     @OA\Property(property="wipeNotes", type="boolean"),
     *     @OA\Property(property="wipeSprite", type="boolean"),
     *     @OA\Property(property="mkpriv", type="boolean", description="Make the appearance private"),
     *     @OA\Property(property="resetPrivKey", type="boolean", description="Generate a new private access token")
     *   )),
     *   @OA\Response(response="204", description="Cleared"),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function clear(Request $request, int $id): Response
    {
        $appearance = $this->managed($request, $id);

        DB::transaction(function () use ($request, $appearance) {
            $group_ids = ColorGroup::where('appearance_id', $appearance->id)->pluck('id');
            switch ($request->input('wipeColors')) {
                case 'color_hex':
                    Color::whereIn('group_id', $group_ids)->update(['hex' => null]);
                    break;
                case 'color_all':
                    Color::whereIn('group_id', $group_ids)->delete();
                    break;
                case 'all':
                    ColorGroup::where('appearance_id', $appearance->id)->delete();
                    break;
            }

            if ($appearance->owner_id === null && $request->boolean('wipeTags')) {
                $tags = $appearance->tags()->get();
                $appearance->tags()->detach();
                $tags->each(fn(Tag $tag) => $tag->updateUses());
            }

            if ($request->boolean('wipeSprite')) {
                $appearance->clearMediaCollection(Appearance::SPRITES_COLLECTION);
                $appearance->forceFill(['sprite_hash' => null]);
            }

            $update = ['last_cleared' => now()];
            if ($request->boolean('wipeNotes')) {
                $update['notes_src'] = null;
                $update['notes_rend'] = null;
            }
            if ($request->boolean('mkpriv')) {
                $update['private'] = true;
            }
            if ($request->boolean('resetPrivKey')) {
                $update['token'] = Uuid::uuid4()->toString();
            }
            $appearance->forceFill($update)->save();
        });
        AppearanceIndex::update($appearance->fresh());

        return response()->noContent();
    }

    /**
     * @OA\Get(
     *   path="/appearances/{id}/color-groups/order",
     *   operationId="GetAppearancesIdColorGroupsOrder",
     *   description="The color groups of an appearance, for reordering. Needs at least 2 color groups. Requires being the owner, or staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false,
     *     @OA\Property(property="cgs", type="array", @OA\Items(type="object", additionalProperties=false, @OA\Property(property="id", ref="#/components/schemas/OneBasedId"), @OA\Property(property="label", type="string")))
     *   )),
     *   @OA\Response(response="409", description="Fewer than 2 color groups", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function colorGroupOrder(Request $request, int $id): JsonResponse
    {
        $appearance = $this->managed($request, $id);
        $groups = $appearance->colorGroups()->get();
        if ($groups->isEmpty()) {
            throw new HttpException(409, 'This appearance does not have any color groups');
        }
        if ($groups->count() < 2) {
            throw new HttpException(409, 'An appearance needs at least 2 color groups before you can change their order');
        }

        return response()->json(['cgs' => $groups->map(fn(ColorGroup $group) => ['id' => $group->id, 'label' => $group->label])->values()]);
    }

    /**
     * @OA\Put(
     *   path="/appearances/{id}/color-groups/order",
     *   operationId="PutAppearancesIdColorGroupsOrder",
     *   description="Reorder the color groups of an appearance. Requires being the owner, or staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"cgs"}, @OA\Property(property="cgs", type="array", description="Color group IDs in the desired order", @OA\Items(ref="#/components/schemas/OneBasedId")))),
     *   @OA\Response(response="200", description="Reordered"),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function setColorGroupOrder(Request $request, int $id): JsonResponse
    {
        $appearance = $this->managed($request, $id);
        $order = $this->intList($request->input('cgs'), 'cgs', 'Color group order data missing', 'Color group order data is invalid', true);

        $old = $appearance->colorGroups()->get();
        $possible = $old->pluck('id')->flip();
        foreach ($order as $group_id) {
            if (!isset($possible[$group_id])) {
                $this->fail('cgs', "There's no group with the ID of $group_id on this appearance");
            }
        }

        DB::transaction(function () use ($order) {
            foreach ($order as $index => $group_id) {
                ColorGroup::whereKey($group_id)->update(['order' => $index]);
            }
        });

        $stringify = fn($groups) => $groups->map(fn($group) => "{$group->id} {$group->label}")->implode("\n");
        $old_string = $stringify($old);
        $new_string = $stringify($appearance->colorGroups()->get());
        if ($old_string !== $new_string) {
            LogWriter::record('cg_order', ['appearance_id' => $appearance->id, 'oldgroups' => $old_string, 'newgroups' => $new_string]);
        }

        return response()->json(new \stdClass());
    }

    /**
     * @OA\Get(
     *   path="/appearances/{id}/relations",
     *   operationId="GetAppearancesIdRelations",
     *   description="Appearances of the same guide split into linked and unlinked ones. Not available for personal guide appearances. Requires staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false,
     *     @OA\Property(property="unlinked", type="array", @OA\Items(type="object", additionalProperties=false, @OA\Property(property="id", ref="#/components/schemas/OneBasedId"), @OA\Property(property="label", type="string"))),
     *     @OA\Property(property="linked", type="array", @OA\Items(type="object", additionalProperties=false, @OA\Property(property="id", ref="#/components/schemas/OneBasedId"), @OA\Property(property="label", type="string"), @OA\Property(property="mutual", type="boolean")))
     *   )),
     *   @OA\Response(response="409", description="Personal guide appearance", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function relations(Request $request, int $id): JsonResponse
    {
        $appearance = $this->managed($request, $id);
        $this->assertOfficial($appearance, 'Relations are unavailable for appearances in personal guides');

        $linked = $appearance->relatedAppearances()->pluck('appearances.id')->flip();
        $mutual = DB::table('related_appearances')->where('target_id', $appearance->id)->pluck('source_id')->flip();
        $pinned = PinnedAppearance::pluck('appearance_id')->all();

        $result = ['unlinked' => [], 'linked' => []];
        $candidates = Appearance::where('guide', $appearance->guide)->whereNotIn('id', [...$pinned, $appearance->id])->orderBy('label')->get(['id', 'label']);
        foreach ($candidates as $candidate) {
            $item = ['id' => $candidate->id, 'label' => $candidate->label];
            if (isset($linked[$candidate->id])) {
                $item['mutual'] = isset($mutual[$candidate->id]);
                $result['linked'][] = $item;
            } else {
                $result['unlinked'][] = $item;
            }
        }

        return response()->json($result);
    }

    /**
     * @OA\Put(
     *   path="/appearances/{id}/relations",
     *   operationId="PutAppearancesIdRelations",
     *   description="Replace the relations of an appearance. Not available for personal guide appearances. Requires staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\RequestBody(@OA\JsonContent(type="object",
     *     @OA\Property(property="ids", type="array", description="IDs of related appearances", @OA\Items(ref="#/components/schemas/OneBasedId")),
     *     @OA\Property(property="mutuals", type="array", description="IDs (a subset of ids) for which the relation should be mutual", @OA\Items(ref="#/components/schemas/OneBasedId"))
     *   )),
     *   @OA\Response(response="200", description="Saved"),
     *   @OA\Response(response="409", description="Personal guide appearance", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function setRelations(Request $request, int $id): JsonResponse
    {
        $appearance = $this->managed($request, $id);
        $this->assertOfficial($appearance, 'Relations are unavailable for appearances in personal guides');

        $ids = $this->intList($request->input('ids'), 'ids', '', 'Appearance ID list is invalid');
        $mutuals = array_flip($this->intList($request->input('mutuals'), 'mutuals', '', 'Mutual relation ID list is invalid'));
        $existing = Appearance::whereIn('id', $ids)->pluck('id')->all();

        DB::transaction(function () use ($appearance, $ids, $mutuals, $existing) {
            DB::table('related_appearances')->where('source_id', $appearance->id)->orWhere('target_id', $appearance->id)->delete();
            $rows = [];
            foreach (array_unique($ids) as $target) {
                if (!in_array($target, $existing, true) || $target === $appearance->id) {
                    continue;
                }
                $rows[] = ['source_id' => $appearance->id, 'target_id' => $target];
                if (isset($mutuals[$target])) {
                    $rows[] = ['source_id' => $target, 'target_id' => $appearance->id];
                }
            }
            DB::table('related_appearances')->insert($rows);
        });

        return response()->json(new \stdClass());
    }

    /**
     * @OA\Get(
     *   path="/appearances/{id}/tags",
     *   operationId="GetAppearancesIdTags",
     *   description="The tags of an appearance as comma-separated text for editing. Not available for personal guide or pinned appearances. Requires staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false, @OA\Property(property="tags", type="string", description="Comma-separated list of tag names"))),
     *   @OA\Response(response="409", description="Tagging is unavailable", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function tags(Request $request, int $id): JsonResponse
    {
        $appearance = $this->taggable($request, $id);

        return response()->json(['tags' => $appearance->tags()->whereNull('synonym_of')->orderBy('name')->pluck('name')->implode(', ')]);
    }

    /**
     * @OA\Put(
     *   path="/appearances/{id}/tags",
     *   operationId="PutAppearancesIdTags",
     *   description="Apply the difference between the original and the new comma-separated tag lists, creating unknown tags. Requires staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"tags"},
     *     @OA\Property(property="origTags", type="string", description="Comma-separated list of tags as they were before editing"),
     *     @OA\Property(property="tags", type="string", description="Comma-separated list of the new tags")
     *   )),
     *   @OA\Response(response="204", description="Saved"),
     *   @OA\Response(response="409", description="Tagging is unavailable", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function setTags(Request $request, int $id): Response
    {
        $appearance = $this->taggable($request, $id);
        $valid = Validator::make($request->all(), [
            'origTags' => ['sometimes', 'nullable', 'string'],
            'tags' => ['present', 'nullable', 'string'],
        ], ['tags.present' => 'List of tags is missing'])->validate();

        $split = fn(?string $list) => array_map('trim', explode(',', (string) $list));
        $old = $split($valid['origTags'] ?? '');
        $new = $split($valid['tags'] ?? '');
        $removed_names = array_diff($old, $new);
        $added_names = array_filter(array_diff($new, $old), fn($name) => $name !== '');

        // Names are validated up front so a bad one does not leave half the changes applied
        foreach ($added_names as $name) {
            $name = strtolower($name);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 64) {
                $this->fail('tags', 'Tag name must be between 2 and 64 characters');
            }
            if ($name[0] === '-') {
                $this->fail('tags', "Tag name ($name) cannot start with a dash");
            }
            if (preg_match('/[^a-z\d ().\-\']/u', $name)) {
                $this->fail('tags', "Tag name ($name) contains invalid characters");
            }
        }

        $user_id = $request->user()->id;
        DB::transaction(function () use ($appearance, $removed_names, $added_names, $user_id) {
            if ($removed_names !== []) {
                foreach (Tag::whereIn('name', $removed_names)->get() as $tag) {
                    $appearance->tags()->detach($tag->id);
                    TagChange::create(['tag_id' => $tag->id, 'appearance_id' => $appearance->id, 'user_id' => $user_id, 'added' => false, 'tag_name' => $tag->name]);
                    $tag->updateUses();
                }
            }
            foreach ($added_names as $name) {
                $name = strtolower($name);
                $tag = Tag::where('name', $name)->first() ?? Tag::create(['name' => $name, 'type' => null]);
                // Adding a synonym applies its target
                $tag = $tag->synonym_of !== null ? Tag::find($tag->synonym_of) : $tag;
                $appearance->tags()->syncWithoutDetaching([$tag->id]);
                TagChange::create(['tag_id' => $tag->id, 'appearance_id' => $appearance->id, 'user_id' => $user_id, 'added' => true, 'tag_name' => $tag->name]);
                $tag->updateUses();
            }
        });
        AppearanceIndex::update($appearance->fresh());

        return response()->noContent();
    }

    /**
     * @OA\Get(
     *   path="/appearances/{id}/shows",
     *   operationId="GetAppearancesIdShows",
     *   description="Every show with whether the appearance is linked to it. Requires staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false,
     *     @OA\Property(property="groups", type="object", description="Map of valid show types to their display names"),
     *     @OA\Property(property="entries", type="array", @OA\Items(type="object", additionalProperties=false, @OA\Property(property="id", ref="#/components/schemas/OneBasedId"), @OA\Property(property="label", type="string"), @OA\Property(property="type", type="string"))),
     *     @OA\Property(property="linkedIds", type="array", @OA\Items(ref="#/components/schemas/OneBasedId"))
     *   ))
     * )
     */
    public function shows(Request $request, int $id): JsonResponse
    {
        $appearance = Appearance::findOrFail($id);

        return response()->json([
            'groups' => ConfigController::SHOW_TYPE_LABELS,
            'entries' => Show::orderByDesc('season')->orderByDesc('episode')->orderByDesc('no')->get()
                ->map(fn(Show $show) => ['id' => $show->id, 'label' => $show->title, 'type' => $show->type])->values(),
            'linkedIds' => $appearance->shows()->pluck('show.id')->values(),
        ]);
    }

    /**
     * @OA\Put(
     *   path="/appearances/{id}/shows",
     *   operationId="PutAppearancesIdShows",
     *   description="Replace the shows an appearance is linked to. Requires staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\RequestBody(@OA\JsonContent(type="object", @OA\Property(property="ids", type="array", description="IDs of shows to link", @OA\Items(ref="#/components/schemas/OneBasedId")))),
     *   @OA\Response(response="200", description="Saved"),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function setShows(Request $request, int $id): JsonResponse
    {
        $appearance = Appearance::findOrFail($id);
        $ids = $this->intList($request->input('ids'), 'ids', '', 'Show ID list is invalid');
        $appearance->shows()->sync(Show::whereIn('id', $ids)->pluck('id')->all());

        return response()->json(new \stdClass());
    }

    /**
     * @OA\Post(
     *   path="/appearances/{id}/pin",
     *   operationId="PostAppearancesIdPin",
     *   description="Pin an official appearance. Requires staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"message"}, @OA\Property(property="message", type="string"))),
     *   @OA\Response(response="409", description="Personal guide appearances cannot be pinned", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     * @OA\Delete(
     *   path="/appearances/{id}/pin",
     *   operationId="DeleteAppearancesIdPin",
     *   description="Unpin an appearance. Requires staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"message"}, @OA\Property(property="message", type="string"))),
     *   @OA\Response(response="409", description="Personal guide appearances cannot be pinned", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function pin(Request $request, int $id): JsonResponse
    {
        $appearance = Appearance::findOrFail($id);
        if ($appearance->guide === null) {
            throw new HttpException(409, 'Appearances in personal guides cannot be pinned');
        }
        $existing = PinnedAppearance::where('appearance_id', $appearance->id)->first();

        if ($request->isMethod('POST')) {
            if ($existing !== null) {
                return response()->json(['message' => 'This appearance is already pinned']);
            }
            PinnedAppearance::create(['appearance_id' => $appearance->id, 'guide' => $appearance->guide->value, 'order' => $appearance->order ?? 0]);
            AppearanceIndex::remove($appearance);

            return response()->json(['message' => 'The appearance has been pinned successfully']);
        }

        if ($existing === null) {
            return response()->json(['message' => 'This appearance was not pinned before']);
        }
        $existing->delete();
        AppearanceIndex::update($appearance);

        return response()->json(['message' => 'The appearance has been unpinned successfully']);
    }

    /**
     * @OA\Put(
     *   path="/appearances/order",
     *   operationId="PutAppearancesOrder",
     *   description="Set the relevance order of the official guide's appearances. Requires staff",
     *   tags={"appearances"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"list", "guide"},
     *     @OA\Property(property="guide", ref="#/components/schemas/GuideName"),
     *     @OA\Property(property="list", type="array", description="Appearance IDs in the desired order", @OA\Items(ref="#/components/schemas/OneBasedId"))
     *   )),
     *   @OA\Response(response="200", description="Reordered"),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function order(Request $request): JsonResponse
    {
        $valid = Validator::make($request->all(), ['guide' => ['required', 'in:'.implode(',', array_column(GuideName::cases(), 'value'))]])->validate();
        $list = $this->intList($request->input('list'), 'list', 'Appearance order data is missing', 'Appearance order data is invalid', true);

        $appearances = Appearance::where('guide', $valid['guide'])->whereIn('id', $list)->get()->keyBy('id');
        foreach ($list as $appearance_id) {
            if (!isset($appearances[$appearance_id])) {
                $this->fail('list', "There's no appearance with the ID of $appearance_id in this guide");
            }
        }

        DB::transaction(function () use ($list, $appearances) {
            foreach ($list as $index => $appearance_id) {
                Appearance::whereKey($appearance_id)->update(['order' => $index + 1]);
            }
        });
        foreach (Appearance::whereIn('id', $list)->get() as $appearance) {
            AppearanceIndex::update($appearance);
        }

        return response()->json(new \stdClass());
    }

    /**
     * @OA\Post(
     *   path="/appearances/{id}/sprite",
     *   operationId="PostAppearancesIdSprite",
     *   description="Upload a new sprite image (PNG, 300px tall, 300 to 700px wide). Requires being the owner, or staff. Owners need the `a_pcgsprite` preference enabled",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(type="object", required={"sprite"}, @OA\Property(property="sprite", ref="#/components/schemas/File")))),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false, @OA\Property(property="path", type="string", description="URL of the newly uploaded sprite"))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permission to manage this appearance", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Appearance not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error, e.g. invalid image format or size", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function uploadSprite(Request $request, int $id): JsonResponse
    {
        $appearance = $this->managed($request, $id);
        $user = $request->user();
        if ($appearance->owner_id === $user->id && !$user->isStaff() && !UserPrefHelper::get($user, UserPrefKey::Admin_CanUploadPcgSprites)) {
            throw new AuthorizationException('You are not allowed to upload sprite images on your own PCG appearances');
        }

        $file = $request->file('sprite');
        if ($file === null || !$file->isValid()) {
            $this->fail('file', 'The sprite file is missing or the upload failed');
        }
        $info = @getimagesize($file->getRealPath());
        if ($info === false || $info[2] !== IMAGETYPE_PNG) {
            $this->fail('file', 'The uploaded file must be a PNG image');
        }
        [$width, $height] = $info;
        if ($width < 300 || $height < 300) {
            $this->fail('file', "The image's dimensions are too small, please upload a larger image. The minimum size is 300px wide by 300px tall, and you uploaded an image that's {$width}px wide and {$height}px tall.");
        }
        if ($width > 700 || $height > 300) {
            $this->fail('file', "The image's dimensions are too big, please upload a smaller image. The maximum size is 700px wide by 300px tall, and you uploaded an image that's {$width}px wide and {$height}px tall.");
        }

        $aspect_ratio = ImageHelper::getAspectRatio($file->getRealPath());
        $hash = md5_file($file->getRealPath());
        $appearance->addMedia($file)
            ->usingFileName(Core::generateHashFilename($file->getRealPath()))
            ->withCustomProperties(['user_id' => $user->id, 'aspect_ratio' => $aspect_ratio])
            ->toMediaCollection(Appearance::SPRITES_COLLECTION);
        $appearance->forceFill(['sprite_hash' => $hash])->save();

        $appearance = $appearance->fresh();

        return response()->json(['path' => ColorGuideHelper::mapSprite($appearance)['path'] ?? null]);
    }

    /**
     * @OA\Delete(
     *   path="/appearances/{id}/sprite",
     *   operationId="DeleteAppearancesIdSprite",
     *   description="Remove the sprite image of an appearance. Requires being the owner, or staff",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/ZeroBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false, @OA\Property(property="sprite", type="string", nullable=true, description="Always null in Luna, clients show their own placeholder"))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permission to manage this appearance", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Appearance not found, or it has no sprite", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function deleteSprite(Request $request, int $id): JsonResponse
    {
        $appearance = $this->managed($request, $id);
        if (!$appearance->hasSprite()) {
            throw new HttpException(404, 'No sprite file found');
        }

        $appearance->clearMediaCollection(Appearance::SPRITES_COLLECTION);
        $appearance->forceFill(['sprite_hash' => null])->save();

        return response()->json(['sprite' => null]);
    }

    /**
     * @OA\Get(
     *   path="/appearances/{id}/tag-changes",
     *   operationId="GetAppearancesIdTagChanges",
     *   description="The history of tags being added to and removed from an appearance, newest first. Staff only; personal guide appearances have no history.",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="query", name="page", required=false, @OA\Schema(type="integer", default=1, minimum=1)),
     *   @OA\Parameter(in="query", name="size", required=false, @OA\Schema(type="integer", default=25, minimum=1, maximum=100)),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"changes", "pagination"},
     *     @OA\Property(property="changes", type="array", @OA\Items(type="object", additionalProperties=false, required={"id", "tagId", "tagName", "added", "user", "createdAt"},
     *       @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *       @OA\Property(property="tagId", type="integer", description="The tag may have been deleted since, in which case it no longer resolves"),
     *       @OA\Property(property="tagName", type="string", nullable=true, description="The tag's name at the time of the change; missing on very old entries"),
     *       @OA\Property(property="added", type="boolean", description="True when the tag was added, false when it was removed"),
     *       @OA\Property(property="user", nullable=true, description="Who made the change; null when the user no longer exists", oneOf={@OA\Schema(ref="#/components/schemas/PostUser")}),
     *       @OA\Property(property="createdAt", type="string", format="date-time")
     *     )),
     *     @OA\Property(property="pagination", ref="#/components/schemas/Pagination")
     *   )),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Appearance not found, or it belongs to a personal guide", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Invalid query", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function tagChanges(Request $request, int $id): JsonResponse
    {
        $appearance = Appearance::findOrFail($id);
        if ($appearance->owner_id !== null) {
            throw new HttpException(404, 'Personal guide appearances have no tag change history');
        }
        $valid = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'between:1,100'],
        ], ['size.between' => 'The size must be between 1 and 100.'])->validate();
        $size = (int) ($valid['size'] ?? 25);

        $pagination = TagChange::where('appearance_id', $appearance->id)->orderByDesc('created_at')->orderByDesc('id')->paginate($size, page: (int) ($valid['page'] ?? 1));
        $users = User::whereIn('id', $pagination->getCollection()->pluck('user_id')->filter()->unique())->get(['id', 'name'])->keyBy('id');

        return response()->json([
            'changes' => $pagination->getCollection()->map(fn(TagChange $change) => [
                'id' => $change->id,
                'tagId' => $change->tag_id,
                'tagName' => $change->tag_name,
                'added' => $change->added,
                'user' => isset($users[$change->user_id]) ? ['id' => $change->user_id, 'name' => $users[$change->user_id]->name] : null,
                'createdAt' => $change->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => [
                'currentPage' => $pagination->currentPage(),
                'totalPages' => max(1, $pagination->lastPage()),
                'totalItems' => $pagination->total(),
                'itemsPerPage' => $size,
            ],
        ]);
    }

    private function managed(Request $request, int $id): Appearance
    {
        $appearance = Appearance::findOrFail($id);
        if (!$appearance->canBeManagedBy($request->user())) {
            throw new AuthorizationException();
        }

        return $appearance;
    }

    private function taggable(Request $request, int $id): Appearance
    {
        $appearance = $this->managed($request, $id);
        $this->assertOfficial($appearance, 'Tagging is unavailable for appearances in personal guides');
        if (PinnedAppearance::where('appearance_id', $appearance->id)->exists()) {
            throw new HttpException(409, 'This appearance cannot be tagged');
        }

        return $appearance;
    }

    private function assertOfficial(Appearance $appearance, string $message): void
    {
        if ($appearance->owner_id !== null) {
            throw new HttpException(409, $message);
        }
    }

    private function checkPersonalCreation($user): void
    {
        if ($user->pcgAvailablePoints() < 10) {
            $count = Appearance::where('owner_id', $user->id)->count();
            $remaining = 10 - ($count % 10);
            throw new HttpException(409, "You don't have enough slots to create another appearance. Delete other ones or finish $remaining more ".($remaining === 1 ? 'request' : 'requests').'.');
        }
        if (!UserPrefHelper::get($user, UserPrefKey::Admin_CanMakePcgAppearances)) {
            throw new AuthorizationException('You are not allowed to create appearances in your Personal Color Guide');
        }
    }

    private function applyTemplateTo(Appearance $appearance): void
    {
        if (ColorGroup::where('appearance_id', $appearance->id)->exists()) {
            throw new \RuntimeException('Template can only be applied to empty appearances');
        }

        $scheme = $appearance->guide === GuideName::EquestriaGirls ? self::HUMAN_TEMPLATE : self::PONY_TEMPLATE;
        DB::transaction(function () use ($appearance, $scheme) {
            $order = 1;
            foreach ($scheme as $group_name => $color_names) {
                $group = ColorGroup::create(['appearance_id' => $appearance->id, 'label' => $group_name, 'order' => $order++]);
                foreach (array_values($color_names) as $index => $label) {
                    Color::create(['group_id' => $group->id, 'label' => $label, 'order' => $index + 1]);
                }
            }
        });
    }

    /**
     * @return array<string, mixed> columns to store
     */
    private function validated(Request $request, ?Appearance $existing, ?GuideName $guide, bool $personal): array
    {
        $input = $request->all();
        // Form style booleans
        if (isset($input['private']) && in_array($input['private'], ['true', 'false'], true)) {
            $input['private'] = $input['private'] === 'true';
        }
        $guide_input = $request->input('guide');
        if (!$personal && $existing === null && $guide === null) {
            $this->fail('guide', "Guide is invalid: $guide_input");
        }

        $valid = Validator::make($input, [
            'label' => ['required', 'string', 'between:2,70', 'regex:/^[ -~\n]+$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'private' => ['sometimes', 'nullable', 'boolean'],
        ], [
            'label.required' => 'Appearance name is missing',
            'label.between' => 'Appearance name must be between 2 and 70 characters long',
            'label.regex' => 'Appearance name contains invalid characters',
            'notes.max' => 'Appearance notes cannot be longer than 1000 characters',
        ])->validate();

        $duplicate = Appearance::where('label', $valid['label'])
            ->when($personal, fn($query) => $query->where('owner_id', $existing?->owner_id ?? $request->user()->id), fn($query) => $query->where('guide', $guide))
            ->when($existing, fn($query) => $query->where('id', '!=', $existing->id))
            ->first();
        if ($duplicate !== null) {
            $this->fail('label', $personal
                ? 'You already have an appearance with the same name in your Personal Color Guide'
                : "An appearance already exists in the {$guide->value} guide with this exact name. Consider adding an identifier in brackets or choosing a different name.");
        }

        $data = ['label' => $valid['label'], 'notes_src' => ($valid['notes'] ?? '') === '' ? null : $valid['notes'], 'private' => (bool) ($valid['private'] ?? false)];
        if ($data['notes_src'] === null) {
            $data['notes_rend'] = null;
        }
        if (!$personal) {
            $data['guide'] = $guide;
        }

        return $data;
    }

    /**
     * @return int[]
     */
    private function intList($value, string $field, string $missing, string $invalid, bool $required = false): array
    {
        if (is_string($value)) {
            $value = $value === '' ? null : explode(',', $value);
        }
        if ($value === null) {
            if ($required) {
                $this->fail($field, $missing);
            }

            return [];
        }
        if (!is_array($value)) {
            $this->fail($field, $invalid);
        }
        foreach ($value as $item) {
            if (!is_numeric($item) || (int) $item < 1) {
                $this->fail($field, $invalid);
            }
        }

        return array_map('intval', array_values($value));
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
