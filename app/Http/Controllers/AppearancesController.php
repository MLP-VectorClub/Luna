<?php

namespace App\Http\Controllers;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Enums\SpriteSize;
use App\Enums\TagType;
use App\Models\Appearance;
use App\Models\Color;
use App\Models\ColorGroup;
use App\Models\Tag;
use App\Models\User;
use App\Utils\Caching;
use App\Utils\ColorGuideHelper;
use App\Utils\Core;
use App\Utils\Permission;
use App\Utils\TagHelper;
use BenSampo\Enum\Rules\EnumValue;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;
use function count;

/**
 * @OA\Schema(
 *   schema="SlimAppearanceList",
 *   type="object",
 *   description="An array of less resource intensive appearances under the appearances key",
 *   required={
 *     "appearances"
 *   },
 *   additionalProperties=false,
 *   @OA\Property(
 *     property="appearances",
 *     type="array",
 *     @OA\Items(ref="#/components/schemas/SlimAppearance")
 *   )
 * )
 * @OA\Schema(
 *   schema="AppearanceList",
 *   type="object",
 *   description="An array of appearances under the appearances key",
 *   required={
 *     "appearances"
 *   },
 *   additionalProperties=false,
 *   @OA\Property(
 *     property="appearances",
 *     type="array",
 *     @OA\Items(ref="#/components/schemas/Appearance")
 *   )
 * )
 * @OA\Schema(
 *   schema="PreviewsIndicator",
 *   type="boolean",
 *   enum={true},
 *   description="Optional parameter that indicates whether you would like to get preview image data with the request. Typically unnecessary unless you want to display a temporary image while the larger image loads."
 * )
 * @OA\Schema(
 *   schema="Order",
 *   type="number",
 *   example=1,
 *   minimum=0,
 *   description="Used for displaying items in a specific order. The API guarantees that array return values are sorted in ascending order based on this property."
 * )
 * @OA\Schema(
 *   schema="ListOfColorGroups",
 *   type="object",
 *   description="Array of color groups under the `colorGroups` key",
 *   required={
 *     "colorGroups"
 *   },
 *   additionalProperties=false,
 *   @OA\Property(
 *     property="colorGroups",
 *     type="array",
 *     minItems=0,
 *     @OA\Items(ref="#/components/schemas/ColorGroup"),
 *    description="Array of color groups belonging to an appearance (may be an empty array)."
 *   )
 * )
 */
class AppearancesController extends Controller
{
    /**
     * @OA\Schema(
     *   schema="CommonAppearance",
     *   type="object",
     *   description="Common properties of the two Appearance schemas",
     *   required={
     *     "id",
     *     "label",
     *     "order",
     *     "sprite",
     *     "hasCutieMarks"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="id",
     *     allOf={
     *       @OA\Schema(ref="#/components/schemas/ZeroBasedId")
     *     }
     *   ),
     *   @OA\Property(
     *     property="label",
     *     type="string",
     *     description="The name of the appearance",
     *     example="Twinkle Sprinkle"
     *   ),
     *   @OA\Property(
     *     property="order",
     *     ref="#/components/schemas/Order"
     *   ),
     *   @OA\Property(
     *     property="sprite",
     *     nullable=true,
     *     description="The sprite that belongs to this appearance, or null if there is none",
     *     allOf={
     *       @OA\Schema(ref="#/components/schemas/Sprite")
     *     }
     *   ),
     *   @OA\Property(
     *     property="hasCutieMarks",
     *     type="boolean",
     *     description="Indicates whether there are any cutie marks tied to this appearance"
     *   )
     * )
     * @OA\Schema(
     *   schema="SlimAppearanceOnly",
     *   type="object",
     *   description="Represents properties that belong to the slim appearance object only",
     *   required={
     *     "characterTagNames",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="characterTagNames",
     *     type="array",
     *     minItems=0,
     *     @OA\Items(
     *       type="string"
     *     )
     *   ),
     *   allOf={
     *     @OA\Schema(ref="#/components/schemas/CommonAppearance")
     *   }
     * )
     * @OA\Schema(
     *   schema="SlimAppearance",
     *   type="object",
     *   description="A less heavy version of the regular Appearance schema",
     *   required={
     *     "characterTagNames",
     *   },
     *   additionalProperties=false,
     *   allOf={
     *     @OA\Schema(ref="#/components/schemas/CommonAppearance"),
     *     @OA\Schema(ref="#/components/schemas/SlimAppearanceOnly")
     *   }
     * )
     * @OA\Schema(
     *   schema="AppearanceOnly",
     *   type="object",
     *   description="Represents properties that belong to the full appearance object only",
     *   required={
     *     "created_at",
     *     "tags",
     *     "notes",
     *     "colorGroups"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="created_at",
     *     ref="#/components/schemas/IsoStandardDate"
     *   ),
     *   @OA\Property(
     *     property="notes",
     *     type="string",
     *     format="html",
     *     nullable=true,
     *     example="Far legs use darker colors. Based on <strong>S2E21</strong>."
     *   ),
     *   @OA\Property(
     *     property="tags",
     *     type="array",
     *     minItems=0,
     *     @OA\Items(ref="#/components/schemas/SlimGuideTag")
     *   )
     * )
     * @OA\Schema(
     *   schema="Appearance",
     *   type="object",
     *   description="Represents an entry in the color guide",
     *   additionalProperties=false,
     *   allOf={
     *     @OA\Schema(ref="#/components/schemas/CommonAppearance"),
     *     @OA\Schema(ref="#/components/schemas/AppearanceOnly"),
     *     @OA\Schema(ref="#/components/schemas/ListOfColorGroups")
     *   }
     * )
     * @param  Appearance  $a
     * @param  bool  $with_previews
     * @param  bool  $compact
     *
     * @return array
     */
    public static function mapAppearance(Appearance $a, bool $with_previews, bool $compact = false): array
    {
        $appearance = [
            'id' => $a->id,
            'label' => $a->label,
            'order' => $a->order,
            'sprite' => self::mapSprite($a, $with_previews),
            'hasCutieMarks' => $a->cutiemarks()->count() !== 0,
        ];

        $tag_mapper = fn (Tag $t) => self::mapTag($t);
        if (!$compact) {
            $appearance['created_at'] = gmdate('c', $a->created_at->getTimestamp());
            $appearance['tags'] = TagHelper::getFor($a->id, true, true)->map($tag_mapper);
            $appearance['notes'] = $a->notes_rend;
            $appearance['colorGroups'] = self::_getColorGroups($a);
        } else {
            $appearance['characterTagNames'] = $a->tags()->where('type', TagType::Character())->pluck('name');
        }

        return $appearance;
    }

    private static function _getColorGroups(Appearance $a): array
    {
        $color_groups = $a->colorGroups()->with('colors');
        return $color_groups->get()->map(fn (ColorGroup $cg) => self::mapColorGroup($cg))->toArray();
    }

    /**
     * @OA\Schema(
     *   schema="SlimGuideTag",
     *   type="object",
     *   @OA\Property(
     *     property="id",
     *     ref="#/components/schemas/OneBasedId"
     *   ),
     *   @OA\Property(
     *     property="name",
     *     type="string",
     *     minLength=1,
     *     maxLength=255,
     *     example="mane six",
     *     description="Tag name (all lowercase)"
     *   ),
     *   @OA\Property(
     *     property="type",
     *     ref="#/components/schemas/TagType"
     *   )
     * )
     * @param  Tag  $t
     *
     * @return array
     */
    public static function mapTag(Tag $t)
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'type' => $t->type,
        ];
    }

    /**
     * @OA\Schema(
     *   schema="Sprite",
     *   type="object",
     *   description="Data related to an appearance's sprite file. The actual file is available from a different endpoint.",
     *   required={
     *     "path",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="path",
     *     type="string",
     *     format="URL",
     *     description="The full URL of the current sprite image"
     *   ),
     *   @OA\Property(
     *     property="preview",
     *     type="string",
     *     format="byte",
     *     example="data:image/png;base64,<image data>",
     *     description="Data URI for a small preview image with matching proportions to the actual image, suitable for displaying as a preview while the full image loads. May not be sent based on the request parameters."
     *   ),
     * )
     * @param  Appearance  $a
     * @param  bool  $with_preview
     *
     * @return array|null
     */
    public static function mapSprite(Appearance $a, $with_preview = false): ?array
    {
        $sprite_file = $a->spriteFile();
        if (!$sprite_file) {
            return null;
        }

        $sprite_file = $a->spriteFile();
        $value = ['path' => $sprite_file->getFullUrl()];

        if ($with_preview) {
            $value['preview'] = Core::fileToDataUri($sprite_file->getPath(Appearance::SPRITE_PREVIEW_CONVERSION));
        }

        return $value;
    }

    /**
     * @OA\Schema(
     *   schema="ColorGroup",
     *   type="object",
     *   description="Groups a list of colors",
     *   required={
     *     "id",
     *     "label",
     *     "order",
     *     "colors"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="id",
     *     ref="#/components/schemas/OneBasedId"
     *   ),
     *   @OA\Property(
     *     property="label",
     *     type="string",
     *     description="The name of the color group",
     *     example="Coat"
     *   ),
     *   @OA\Property(
     *     property="order",
     *     ref="#/components/schemas/Order"
     *   ),
     *   @OA\Property(
     *     property="colors",
     *     type="array",
     *     minItems=1,
     *     @OA\Items(ref="#/components/schemas/Color"),
     *     description="The list of colors inside this group"
     *   )
     * )
     * @param ColorGroup $cg
     *
     * @return array
     */
    public static function mapColorGroup(ColorGroup $cg)
    {
        $colors = $cg->colors()->get()->map(fn (Color $c) => self::mapColor($c));

        return [
            'id' => $cg->id,
            'label' => $cg->label,
            'order' => $cg->order,
            'colors' => $colors,
        ];
    }

    /**
     * @OA\Schema(
     *   schema="Color",
     *   type="object",
     *   description="A color entry",
     *   required={
     *     "id",
     *     "label",
     *     "order",
     *     "hex"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="id",
     *     ref="#/components/schemas/OneBasedId"
     *   ),
     *   @OA\Property(
     *     property="label",
     *     type="string",
     *     description="The name of the color",
     *     example="Fill"
     *   ),
     *   @OA\Property(
     *     property="order",
     *     ref="#/components/schemas/Order"
     *   ),
     *   @OA\Property(
     *     property="hex",
     *     type="string",
     *     format="#RRGGBB",
     *     description="The color value in uppercase hexadecimal form, including a # prefix",
     *     example="#6181B6"
     *   )
     * )
     * @param  Color  $c
     *
     * @return array
     */
    public static function mapColor(Color $c)
    {
        return [
            'id' => $c->id,
            'label' => $c->label,
            'order' => $c->order,
            'hex' => $c->hex,
        ];
    }

    /**
     * @OA\Schema(
     *   schema="GuidePageSize",
     *   type="integer",
     *   minimum=7,
     *   maximum=20,
     *   default=7
     * )
     * @OA\Get(
     *   path="/appearances",
     *   description="Allows querying the full library of public appearances (forced pagination)",
     *   tags={"appearances"},
     *   security={},
     *   @OA\Parameter(
     *     in="query",
     *     name="guide",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/GuideName"),
     *     description="Determines the guide to search in"
     *   ),
     *   @OA\Parameter(
     *     in="query",
     *     name="page",
     *     required=false,
     *     @OA\Schema(ref="#/components/schemas/PageNumber"),
     *     description="Which page of results to return"
     *   ),
     *   @OA\Parameter(
     *     in="query",
     *     name="size",
     *     required=false,
     *     @OA\Schema(ref="#/components/schemas/GuidePageSize"),
     *     description="The number of results to return per page"
     *   ),
     *   @OA\Parameter(
     *     in="query",
     *     name="q",
     *     required=false,
     *     @OA\Schema(ref="#/components/schemas/QueryString"),
     *     description="Search query"
     *   ),
     *   @OA\Parameter(
     *     in="query",
     *     name="previews",
     *     required=false,
     *     @OA\Schema(ref="#/components/schemas/PreviewsIndicator")
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="OK",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/AppearanceList"),
     *         @OA\Schema(ref="#/components/schemas/PageData")
     *       }
     *     )
     *   ),
     *   @OA\Response(
     *     response="503",
     *     description="Temporarily Unavailable",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *       }
     *     )
     *   )
     * )
     * @param  Request  $request
     * @return JsonResponse|Response
     * @throws ValidationException
     */
    public function queryPublic(Request $request)
    {
        if (!ColorGuideHelper::isElasticAvailable()) {
            return response()->json(['message' => trans('errors.color_guide.elastic_down')], 503);
        }

        $valid = Validator::make($request->all(), [
            'guide' => ['required', new EnumValue(GuideName::class)],
            'size' => 'sometimes|numeric|between:7,20',
            'q' => 'sometimes|string',
            'previews' => 'sometimes|required|accepted',
            'page' => 'sometimes|required|int|min:1',
        ])->validate();

        $guide_name = new GuideName($valid['guide']);
        $appearances_per_page = $valid['size'] ?? 7;
        $query = !empty($valid['q']) ? $valid['q'] : null;
        $with_previews = $valid['previews'] ?? false;
        $page = $valid['page'] ?? 1;
        $pagination = ColorGuideHelper::searchGuide($page, $appearances_per_page, $guide_name, $query);
        $results = $pagination->getCollection()->map(fn (Appearance $a) => self::mapAppearance($a, $with_previews));
        return response()->json([
            'appearances' => $results,
            'pagination' => Core::mapPagination($pagination),
        ]);
    }

    /**
     * @OA\Get(
     *   path="/appearances/all",
     *   description="Get a list of every appearance in the database (without color group data)",
     *   tags={"appearances"},
     *   security={},
     *   @OA\Parameter(
     *     in="query",
     *     name="guide",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/GuideName"),
     *     description="Determines the guide to search in"
     *   ),
     *   @OA\Parameter(
     *     in="query",
     *     name="previews",
     *     required=false,
     *     @OA\Schema(ref="#/components/schemas/PreviewsIndicator")
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="OK",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/SlimAppearanceList")
     *       }
     *     )
     *   )
     * )
     * @param  Request  $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function queryAll(Request $request)
    {
        $valid = Validator::make($request->all(), [
            'guide' => ['required', new EnumValue(GuideName::class)],
            'previews' => 'sometimes|required|accepted',
        ])->validate();

        $guide_name = $valid['guide'];
        $with_previews = $valid['previews'] ?? false;

        /** @var $appearances Appearance[] */
        $appearances = Appearance::ordered()->where('guide', $guide_name)->where('id', '!=', 0)->get();

        $results = $appearances->map(fn (Appearance $a) => self::mapAppearance($a, $with_previews, true));

        return response()->json([
            'appearances' => $results,
        ]);
    }

    /**
     * @param  Request  $request
     * @return Appearance|Response
     */
    private static function _resolveAppearance(Request $request)
    {
        $id = (int) $request->get('id');
        return Appearance::findOrFail($id);
    }

    private static function _handlePrivateAppearanceCheck(Request $request, Appearance $appearance): ?JsonResponse
    {
        if ($appearance->private && Permission::insufficient(Role::Staff())) {
            /** @var User $user */
            $user = Auth::user();
            if ($user && $appearance->owner_id === $user->id) {
                return null;
            }

            // TODO Check token parameter and allow if matches

            return response()->json(['message' => trans('errors.color_guide.appearance_private')], 403);
        }

        return null;
    }

    /**
     * @OA\Get(
     *   path="/appearances/{id}/color-groups",
     *   description="Get all color groups associated with an appearance",
     *   tags={"appearances"},
     *   security={},
     *   @OA\Parameter(
     *     in="path",
     *     name="id",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/ZeroBasedId")
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="OK",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ListOfColorGroups")
     *       }
     *     )
     *   )
     * )
     * @param  Request  $request
     * @param  Appearance  $appearance
     * @return JsonResponse|Response
     */
    public function getColorGroups(Request $request, Appearance $appearance)
    {
        if ($error = self::_handlePrivateAppearanceCheck($request, $appearance)) {
            return $error;
        }

        return response()->json(['colorGroups' => self::_getColorGroups($appearance)]);
    }

    /**
     * @OA\Schema(
     *   schema="AppearanceToken",
     *   type="string",
     *   format="uuid"
     * )
     * @OA\Schema(
     *   schema="LocationHeader",
     *   description="Contains a URL that most clients will automatically redirect to for 301 and 302 responses",
     *   type="string",
     *   format="URL"
     * )
     * @OA\Get(
     *   path="/appearances/{id}/sprite",
     *   description="Fetch the sprite file associated with the appearance",
     *   tags={"appearances"},
     *   security={},
     *   @OA\Parameter(
     *     in="path",
     *     name="id",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/ZeroBasedId")
     *   ),
     *   @OA\Parameter(
     *     in="query",
     *     name="size",
     *     @OA\Schema(ref="#/components/schemas/SpriteSize")
     *   ),
     *   @OA\Parameter(
     *     in="query",
     *     name="token",
     *     @OA\Schema(ref="#/components/schemas/AppearanceToken")
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="The sprite image data (if the appearance is private)",
     *     @OA\MediaType(
     *       mediaType="image/png",
     *       @OA\Schema(ref="#/components/schemas/File")
     *     )
     *   ),
     *   @OA\Response(
     *     response="302",
     *     description="Redirect to the current sprite image URL (if the appearance is public).",
     *     @OA\Header(header="Location", ref="#/components/schemas/LocationHeader")
     *   ),
     *   @OA\Response(
     *     response="404",
     *     description="Sprite image missing",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *       }
     *     )
     *   ),
     *   @OA\Response(
     *     response="403",
     *     description="You don't have permission to access this resource",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *       }
     *     )
     *   )
     * )
     * @param  Request  $request
     * @param  Appearance  $appearance
     */
    public function sprite(Request $request, Appearance $appearance)
    {
        if ($error = self::_handlePrivateAppearanceCheck($request, $appearance)) {
            return $error;
        }

        $sprite_file = $appearance->spriteFile();
        if ($sprite_file === null) {
            return response()->noContent(404);
        }

        $params = Validator::make($request->only('size'), [
            'size' => ['required', 'integer', new EnumValue(SpriteSize::class)],
        ])->valid();
        $double_size = isset($params['size']) && $params['size'] === SpriteSize::Double();

        if ($appearance->owner_id === null) {
            $url = $sprite_file->getUrl($double_size ? Appearance::DOUBLE_SIZE_CONVERSION : '');
            return redirect($url);
        }

        $sprite_path = $sprite_file->getPath($double_size ? Appearance::DOUBLE_SIZE_CONVERSION : '');

        return response()->file($sprite_path, ['cache-control' => 'private, must-revalidate']);
    }

    /**
     * @OA\Get(
     *   path="/appearances/{id}/preview",
     *   description="Fetch the preview file associated with the appearance",
     *   tags={"appearances"},
     *   security={},
     *   @OA\Parameter(
     *     in="path",
     *     name="id",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/ZeroBasedId")
     *   ),
     *   @OA\Parameter(
     *     in="query",
     *     name="token",
     *     @OA\Schema(ref="#/components/schemas/AppearanceToken")
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="The appearance preview image",
     *     @OA\MediaType(
     *       mediaType="image/svg+xml",
     *       @OA\Schema(ref="#/components/schemas/SVGFile")
     *     )
     *   ),
     *   @OA\Response(
     *     response="404",
     *     description="Appearance missing",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *       }
     *     )
     *   ),
     *   @OA\Response(
     *     response="403",
     *     description="You don't have permission to access this resource",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *       }
     *     )
     *   )
     * )
     * @param  Request  $request
     * @param  Appearance  $appearance
     * @return JsonResponse|Response
     */
    public function preview(Request $request, Appearance $appearance)
    {
        if ($error = self::_handlePrivateAppearanceCheck($request, $appearance)) {
            return $error;
        }

        // TODO CGUtils::renderPreviewSVG($appearance);
        return response()->noContent(404);
    }
}
