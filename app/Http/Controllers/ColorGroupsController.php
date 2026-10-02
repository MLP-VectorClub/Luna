<?php

namespace App\Http\Controllers;

use App\Models\Appearance;
use App\Models\Color;
use App\Models\ColorGroup;
use App\Models\MajorChange;
use App\Utils\HexColor;
use App\Utils\LogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;

class ColorGroupsController extends Controller
{
    /**
     * @OA\Schema(
     *   schema="ColorGroupInput",
     *   type="object",
     *   required={"label", "colors"},
     *   @OA\Property(property="appearanceId", ref="#/components/schemas/OneBasedId", description="Only when creating"),
     *   @OA\Property(property="label", type="string", minLength=2, maxLength=30),
     *   @OA\Property(property="colors", type="array", minItems=1, description="Existing colors should include their id, omitted existing colors are deleted",
     *     @OA\Items(type="object", required={"label"},
     *       @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *       @OA\Property(property="label", type="string", minLength=3, maxLength=30),
     *       @OA\Property(property="hex", type="string", nullable=true)
     *     )
     *   ),
     *   @OA\Property(property="major", type="boolean", description="Record as a major change (official guide only)"),
     *   @OA\Property(property="reason", type="string", maxLength=255, description="Required when major is set")
     * )
     * @OA\Get(
     *   path="/color-groups/{id}",
     *   operationId="GetColorGroupsId",
     *   description="Get a color group with its colors. Requires being the owner of the appearance, or staff",
     *   tags={"color groups"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/PrivateColorGroup")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $group = $this->load($request, $id);

        return response()->json([
            'id' => $group->id,
            'appearanceId' => $group->appearance_id,
            'order' => $group->order,
            'label' => $group->label,
            'colors' => $group->colors()->orderBy('order')->get()->map(fn(Color $color) => [
                'id' => $color->id,
                'order' => $color->order,
                'label' => $color->label,
                'hex' => $color->hex,
            ])->all(),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/color-groups",
     *   operationId="PostColorGroups",
     *   description="Create a color group on an appearance. Requires being the owner of the appearance, or staff",
     *   tags={"color groups"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/ColorGroupInput")),
     *   @OA\Response(response="201", description="Created", @OA\JsonContent(type="object", required={"id"}, @OA\Property(property="id", ref="#/components/schemas/OneBasedId"))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Appearance not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function create(Request $request): JsonResponse
    {
        $valid = Validator::make($request->all(), ['appearanceId' => ['required', 'integer', 'min:0']], [
            'appearanceId.required' => 'Appearance ID is missing',
            'appearanceId.integer' => 'Appearance ID is invalid',
        ])->validate();
        $appearance = Appearance::findOrFail($valid['appearanceId']);
        if (!$appearance->canBeManagedBy($request->user())) {
            throw new AuthorizationException();
        }

        $group = new ColorGroup(['appearance_id' => $appearance->id]);
        $this->save($request, $group, $appearance);

        return response()->json(['id' => $group->id], 201);
    }

    /**
     * @OA\Put(
     *   path="/color-groups/{id}",
     *   operationId="PutColorGroupsId",
     *   description="Replace a color group's label and colors. Requires being the owner of the appearance, or staff",
     *   tags={"color groups"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/ColorGroupInput")),
     *   @OA\Response(response="200", description="Updated", @OA\JsonContent(type="object", required={"id"}, @OA\Property(property="id", ref="#/components/schemas/OneBasedId"))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $group = $this->load($request, $id);
        $this->save($request, $group, $group->appearance);

        return response()->json(['id' => $group->id]);
    }

    /**
     * @OA\Delete(
     *   path="/color-groups/{id}",
     *   operationId="DeleteColorGroupsId",
     *   description="Delete a color group. Requires being the owner of the appearance, or staff",
     *   tags={"color groups"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="204", description="Deleted"),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, int $id): Response
    {
        $group = $this->load($request, $id);
        $group->delete();

        LogWriter::record('cgs', [
            'action' => 'del',
            'group_id' => $group->id,
            'appearance_id' => $group->appearance_id,
            'label' => $group->label,
            'order' => $group->order,
        ]);

        return response()->noContent();
    }

    private function load(Request $request, int $id): ColorGroup
    {
        $group = ColorGroup::with('appearance')->findOrFail($id);
        if (!$group->appearance->canBeManagedBy($request->user())) {
            throw new AuthorizationException();
        }

        return $group;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function save(Request $request, ColorGroup $group, Appearance $appearance): void
    {
        $creating = !$group->exists;
        $old_label = $group->label;

        $valid = Validator::make($request->all(), [
            'label' => ['required', 'string', 'between:2,30', 'regex:/^[ -~\n]+$/'],
        ], [
            'label.required' => 'Color group label is missing',
            'label.between' => 'Color group label must be between 2 and 30 characters long',
            'label.regex' => 'Color group label contains invalid characters',
        ])->validate();
        $label = $valid['label'];

        $duplicate = ColorGroup::where('appearance_id', $appearance->id)->where('label', $label)
            ->when(!$creating, fn($query) => $query->where('id', '!=', $group->id))->exists();
        if ($duplicate) {
            $this->fail('label', 'There is already a color group with the same name on this appearance.');
        }

        $official = $appearance->owner_id === null;
        $major = $official && $request->boolean('major');
        $reason = null;
        if ($major) {
            $reason = $request->input('reason');
            if (!is_string($reason) || trim($reason) === '') {
                $this->fail('reason', 'Please specify a reason for the changes');
            }
            if (mb_strlen($reason) > 255) {
                $this->fail('reason', 'The reason cannot be longer than 255 characters');
            }
        }

        $received = $request->input('colors');
        if (is_string($received)) {
            $received = json_decode($received, true);
        }
        if ($received === null) {
            $this->fail('colors', 'Missing list of colors');
        }
        if (!is_array($received)) {
            $this->fail('colors', 'List of colors is invalid');
        }
        $received = array_values($received);
        if (count($received) < 1) {
            $this->fail('colors', 'Each color group must have at least one color');
        }

        $old_colors = $creating ? collect() : $group->colors()->orderBy('order')->get();
        $kept = [];
        $prepared = [];
        $seen_labels = [];
        foreach ($received as $index => $input) {
            if (!is_array($input)) {
                $this->fail('colors', 'List of colors is invalid');
            }
            if (!empty($input['id'])) {
                $color = Color::find($input['id']);
                if ($color === null) {
                    $this->fail('colors', "Trying to edit color with ID {$input['id']} which does not exist");
                }
                if ($color->group_id !== $group->id) {
                    $this->fail('colors', "Trying to modify color with ID {$input['id']} which is not part of the color group you're editing");
                }
                $where = "(ID: {$input['id']})";
                $kept[] = $color->id;
            } else {
                $color = new Color();
                $where = "(index: $index)";
            }

            $color_label = trim((string) ($input['label'] ?? ''));
            if ($color_label === '') {
                $this->fail('colors', "You must specify a color name $where");
            }
            if (!preg_match('/^[ -~\n]+$/', $color_label)) {
                $this->fail('colors', "Color $where name contains invalid characters");
            }
            $length = mb_strlen($color_label);
            if ($length < 3 || $length > 30) {
                $this->fail('colors', "The color name must be between 3 and 30 characters in length $where");
            }
            if (isset($seen_labels[$color_label])) {
                $this->fail('colors', "The color name \"$color_label\" appears in this color group more than once. Please choose a unique name or add numbering to the colors.");
            }
            $seen_labels[$color_label] = true;

            $color->label = $color_label;
            $color->order = $index + 1;
            if (!empty($input['hex'])) {
                $hex = HexColor::normalize((string) $input['hex']);
                if ($hex === null) {
                    $this->fail('colors', 'Hex color '.trim((string) $input['hex'])." is invalid, please leave empty or fix $where");
                }
                $color->hex = $official ? HexColor::round($hex) : $hex;
            }
            $prepared[] = $color;
        }
        // Nothing is written before this point, so a rejected request changes nothing

        DB::transaction(function () use ($group, $label, $prepared, $kept, $creating, $appearance, $major, $reason, $request) {
            $group->label = $label;
            if ($creating) {
                $group->order = (ColorGroup::where('appearance_id', $appearance->id)->max('order') ?? 0) + 1;
            }
            $group->save();

            foreach ($prepared as $color) {
                $color->group_id = $group->id;
                $color->save();
            }
            if (!$creating) {
                Color::where('group_id', $group->id)->whereNotIn('id', $kept)->whereNotIn('id', collect($prepared)->pluck('id')->filter())->delete();
            }

            if ($major) {
                MajorChange::create(['appearance_id' => $appearance->id, 'reason' => $reason, 'user_id' => $request->user()->id]);
            }
        });

        if ($creating) {
            LogWriter::record('cgs', [
                'action' => 'add',
                'group_id' => $group->id,
                'appearance_id' => $group->appearance_id,
                'label' => $group->label,
                'order' => $group->order,
            ]);
        }

        $log = [];
        if (!$creating && $old_label !== $group->label) {
            $log['oldlabel'] = $old_label;
            $log['newlabel'] = $group->label;
        }
        $stringify = fn($colors) => $colors->isEmpty() ? null : $colors->map(fn($color) => "{$color->hex} {$color->label}")->implode("\n");
        $old_string = $stringify($old_colors);
        $new_string = $stringify(collect($prepared));
        if ($old_string !== $new_string) {
            $log['oldcolors'] = $old_string;
            $log['newcolors'] = $new_string;
        }
        if ($log !== []) {
            LogWriter::record('cg_modify', $log + ['group_id' => $group->id, 'appearance_id' => $group->appearance_id]);
        }
    }
}
