<?php

namespace App\Http\Controllers;

use App\Enums\CutieMarkFacing;
use App\Exceptions\ImageProviderException;
use App\Models\Appearance;
use App\Models\Color;
use App\Models\ColorGroup;
use App\Models\CutieMark;
use App\Models\DeviantartUser;
use App\Utils\ImageProvider;
use App\Utils\LogWriter;
use App\Utils\SvgHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;

class CutieMarksController extends Controller
{
    private const MAX_CUTIE_MARKS = 4;
    private const MAX_SVG_BYTES = 1048576;

    /**
     * @OA\Get(
     *   path="/appearances/{id}/cutie-marks",
     *   operationId="GetAppearancesIdCutieMarks",
     *   description="Get the cutie marks associated with an appearance. The user must have permission to manage the appearance.",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false,
     *     @OA\Property(property="cms", type="array", @OA\Items(type="object", additionalProperties=true,
     *       @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *       @OA\Property(property="appearanceId", ref="#/components/schemas/OneBasedId"),
     *       @OA\Property(property="facing", type="string", nullable=true),
     *       @OA\Property(property="rotation", type="integer"),
     *       @OA\Property(property="label", type="string", nullable=true),
     *       @OA\Property(property="deviation", type="string", format="uri", description="Present if the cutie mark is attributed to a deviation"),
     *       @OA\Property(property="username", type="string", description="Present if the cutie mark is attributed to a contributor"),
     *       @OA\Property(property="rendered", type="string", format="uri", description="URL of the cutie mark image")
     *     ))
     *   )),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permission to manage this appearance", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Appearance not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request, int $id): JsonResponse
    {
        $appearance = $this->managed($request, $id);

        return response()->json(['cms' => $appearance->cutiemarks()->orderBy('id')->get()->map(fn(CutieMark $cm) => $this->present($cm))->all()]);
    }

    /**
     * @OA\Put(
     *   path="/appearances/{id}/cutie-marks",
     *   operationId="PutAppearancesIdCutieMarks",
     *   description="Replace the cutie marks of an appearance (up to 4). Cutie marks missing from the list are removed. The user must have permission to manage the appearance.",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"cutieMarks"},
     *     @OA\Property(property="cutieMarks", type="array", description="List of cutie mark definitions, max 4 items", @OA\Items(type="object", required={"attribution", "rotation"},
     *       @OA\Property(property="id", ref="#/components/schemas/OneBasedId", description="ID of an existing cutie mark to update; omit to create a new one"),
     *       @OA\Property(property="svgdata", ref="#/components/schemas/SVGFile", description="Required when creating a new cutie mark, max 1MB"),
     *       @OA\Property(property="label", type="string", nullable=true, minLength=1, maxLength=32, description="Unique-per-appearance display label"),
     *       @OA\Property(property="facing", type="string", nullable=true, description="Body orientation this cutie mark applies to"),
     *       @OA\Property(property="attribution", type="string", enum={"deviation", "user", "none"}),
     *       @OA\Property(property="deviation", type="string", format="uri", description="Deviation URL, required when attribution is 'deviation'"),
     *       @OA\Property(property="username", type="string", description="DeviantArt username, required when attribution is 'user'"),
     *       @OA\Property(property="rotation", type="integer", minimum=-45, maximum=45, description="Preview rotation amount in degrees")
     *     )),
     *     @OA\Property(property="appearancePage", type="boolean", description="Accepted for compatibility with Winterchilla, has no effect")
     *   )),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false)),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permission to manage this appearance", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Appearance not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error in one of the cutie mark entries", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function replace(Request $request, int $id): JsonResponse
    {
        $appearance = $this->managed($request, $id);
        $current = $appearance->cutiemarks()->orderBy('id')->get()->keyBy('id');

        $items = $request->input('cutieMarks');
        if ($items === null || $items === '') {
            $this->fail('Cutie mark data is missing', 'cutieMarks');
        }
        if (is_string($items)) {
            $items = json_decode($items, true);
        }
        if (!is_array($items) || ($items !== [] && !array_is_list($items))) {
            $this->fail('Cutie mark data is invalid', 'cutieMarks');
        }
        if (count($items) > self::MAX_CUTIE_MARKS) {
            $this->fail('Appearances can only have a maximum of '.self::MAX_CUTIE_MARKS.' cutie marks.');
        }

        // Everything is checked before anything is written
        $prepared = [];
        $labels = [];
        $kept_ids = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $this->fail('Cutie mark data is invalid');
            }
            $cm = null;
            if (isset($item['id'])) {
                $cm = $current->get((int) $item['id']);
                if ($cm === null) {
                    $this->fail("The cutie mark you're trying to update (#{$item['id']}) does not exist");
                }
                $kept_ids[] = $cm->id;
            }

            $svg = null;
            if ($cm === null || !empty($item['svgdata'])) {
                $raw = $item['svgdata'] ?? '';
                if (!is_string($raw) || $raw === '') {
                    $this->fail('SVG data is missing');
                }
                if (strlen($raw) > self::MAX_SVG_BYTES) {
                    $this->fail('SVG data exceeds the maximum size of 1 MB');
                }
                $svg = SvgHelper::sanitize($raw);
                if ($svg === null) {
                    $this->fail('SVG data is invalid');
                }
            }

            $attributes = ['label' => null, 'facing' => null, 'favme' => null, 'contributor_id' => null];

            $label = isset($item['label']) && is_string($item['label']) ? trim($item['label']) : '';
            if ($label !== '') {
                if (!preg_match('/^[ -~]+$/', $label)) {
                    $this->fail('Cutie Mark label contains invalid characters');
                }
                if (mb_strlen($label) > 32) {
                    $this->fail('Cutie mark label must be between 1 and 32 chars long');
                }
                if (isset($labels[$label])) {
                    $this->fail('Cutie mark labels must be unique within an appearance');
                }
                $labels[$label] = true;
                $attributes['label'] = $label;
            }

            $facing = isset($item['facing']) && is_string($item['facing']) ? trim($item['facing']) : '';
            if ($facing !== '') {
                if (CutieMarkFacing::tryFrom($facing) === null) {
                    $this->fail("Body orientation \"$facing\" is invalid");
                }
                $attributes['facing'] = $facing;
            }

            switch ($item['attribution'] ?? null) {
                case 'deviation':
                    $url = is_string($item['deviation'] ?? null) ? trim($item['deviation']) : '';
                    if ($url === '') {
                        $this->fail('Deviation link is missing');
                    }
                    try {
                        $image = ImageProvider::resolve($url, ImageProvider::DEVIATION_PROVIDERS, false);
                    } catch (ImageProviderException $e) {
                        $this->fail($e->actualProvider !== null
                            ? "The link must point to a DeviantArt submission, {$e->actualProvider} links are not allowed"
                            : "Error while checking deviation link: {$e->getMessage()}");
                    }
                    $attributes['favme'] = $image->id;
                    $attributes['contributor_id'] = $this->contributor($image->author, "The provided deviation's creator could not be found")->id;
                    break;
                case 'user':
                    $username = is_string($item['username'] ?? null) ? trim($item['username']) : '';
                    if ($username === '') {
                        $this->fail('Username is missing');
                    }
                    if (!preg_match('/^[A-Za-z\-\d]{1,20}$/', $username)) {
                        $this->fail("Username ($username) is invalid");
                    }
                    $attributes['contributor_id'] = $this->contributor($username, "No DeviantArt user with the name $username is known to the site")->id;
                    break;
                case 'none':
                    break;
                default:
                    $this->fail('The specified attribution method is invalid');
            }

            if (!isset($item['rotation'])) {
                $this->fail('Preview rotation amount is missing');
            }
            if (!is_numeric($item['rotation'])) {
                $this->fail('Preview rotation must be a number');
            }
            $attributes['rotation'] = (int) $item['rotation'];
            if (abs($attributes['rotation']) > 45) {
                $this->fail('Preview rotation must be between -45 and 45');
            }

            $prepared[] = [$cm, $attributes, $svg];
        }

        $old_data = $this->logData($current->all());
        DB::transaction(function () use ($appearance, $prepared, $current, $kept_ids) {
            foreach ($current as $cm) {
                if (!in_array($cm->id, $kept_ids, true)) {
                    $cm->delete();
                }
            }
            foreach ($prepared as [$cm, $attributes, $svg]) {
                $cm ??= new CutieMark(['appearance_id' => $appearance->id]);
                $cm->fill($attributes)->save();
                if ($svg !== null) {
                    $cm->addMediaFromString($svg)
                        ->usingFileName(sha1($svg).'.svg')
                        ->withCustomProperties(['user_id' => request()->user()->id])
                        ->toMediaCollection(CutieMark::CUTIEMARKS_COLLECTION);
                }
            }
        });

        $new_data = $this->logData($appearance->cutiemarks()->orderBy('id')->get()->all());
        if ($prepared === []) {
            if ($current->isNotEmpty()) {
                LogWriter::record('cm_delete', ['appearance_id' => $appearance->id, 'data' => $old_data]);
            }
        } elseif ($old_data !== $new_data) {
            LogWriter::record('cm_modify', ['appearance_id' => $appearance->id, 'olddata' => $old_data, 'newdata' => $new_data]);
        }

        return response()->json(new \stdClass());
    }

    /**
     * @OA\Post(
     *   path="/appearances/{id}/sanitize-svg",
     *   operationId="PostAppearancesIdSanitizeSvg",
     *   description="Upload and sanitize an SVG file for use as a cutie mark, returning the sanitized SVG markup. The user must be signed in and have permission to manage the appearance.",
     *   tags={"appearances"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data",
     *     @OA\Schema(type="object", required={"file"}, @OA\Property(property="file", ref="#/components/schemas/SVGFile"))
     *   )),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", additionalProperties=false,
     *     @OA\Property(property="svgel", type="string", description="Sanitized SVG markup"),
     *     @OA\Property(property="svgdata", type="string", description="Original uploaded SVG markup"),
     *     @OA\Property(property="keepDialog", type="boolean"),
     *     @OA\Property(property="warnings", type="array", @OA\Items(type="string"))
     *   )),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permission to manage this appearance", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Appearance not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="SVG data is missing, invalid or too large", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function sanitize(Request $request, int $id): JsonResponse
    {
        $appearance = $this->managed($request, $id);

        $file = $request->file('file');
        if ($file === null || !$file->isValid()) {
            throw ValidationException::withMessages(['file' => 'SVG data is missing']);
        }
        if ($file->getSize() > self::MAX_SVG_BYTES) {
            throw ValidationException::withMessages(['file' => 'SVG file size exceeds '.self::MAX_SVG_BYTES.' bytes.']);
        }
        $original = file_get_contents($file->getRealPath());

        $warnings = [];
        $svg = SvgHelper::sanitize($original, $warnings);
        if ($svg === null) {
            throw ValidationException::withMessages(['file' => 'SVG data is invalid']);
        }

        $group = ColorGroup::where('appearance_id', $appearance->id)->where('label', 'Cutie Mark')->first();
        if ($group !== null) {
            $known = Color::where('group_id', $group->id)->whereNotNull('hex')->pluck('hex')->map(fn($hex) => strtolower(substr($hex, 0, 7)))->all();
            $warnings = array_merge($warnings, SvgHelper::unknownColorWarnings($svg, $known));
        }

        return response()->json(['svgel' => $svg, 'svgdata' => SvgHelper::uncompress($original), 'keepDialog' => true, 'warnings' => $warnings]);
    }

    private function managed(Request $request, int $id): Appearance
    {
        $appearance = Appearance::findOrFail($id);
        if (!$appearance->canBeManagedBy($request->user())) {
            throw new AuthorizationException();
        }

        return $appearance;
    }

    private function present(CutieMark $cm): array
    {
        $result = [
            'id' => $cm->id,
            'appearanceId' => $cm->appearance_id,
            'facing' => $cm->facing,
            'rotation' => $cm->rotation,
            'label' => $cm->label,
        ];
        if ($cm->favme !== null) {
            $result['deviation'] = 'http://fav.me/'.$cm->favme;
        }
        if ($cm->contributor_id !== null) {
            $result['username'] = $cm->contributor?->name;
        }
        $result['rendered'] = $cm->vectorFile()?->getFullUrl();

        return $result;
    }

    /**
     * @param  CutieMark[]  $cutiemarks
     */
    private function logData(array $cutiemarks): string
    {
        $out = [];
        foreach ($cutiemarks as $cm) {
            $out[$cm->id] = $cm->only(['facing', 'favme', 'rotation', 'contributor_id', 'label']);
        }

        return json_encode($out);
    }

    private function contributor(?string $name, string $missing): DeviantartUser
    {
        $user = $name === null ? null : DeviantartUser::where('name', $name)->first();
        if ($user === null) {
            $this->fail($missing);
        }

        return $user;
    }

    private function fail(string $message, string $field = 'cutiemarks'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
