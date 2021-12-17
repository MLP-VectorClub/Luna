<?php

namespace App\Http\Controllers;

use App\Enums\FullGuideSortField;
use App\Enums\GuideName;
use App\Enums\Role;
use App\Enums\SpriteSize;
use App\Enums\UserPrefKey;
use App\Models\Appearance;
use App\Models\PinnedAppearance;
use App\Models\User;
use App\Utils\Caching;
use App\Utils\ColorGuideHelper;
use App\Utils\Core;
use App\Utils\Permission;
use App\Utils\UserPrefHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;

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
 */

/**
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
     *   schema="GuidePageSize",
     *   type="integer",
     *   minimum=7,
     *   maximum=20,
     *   default=7,
     *   description="The number of results to return per page"
     * )
     * @OA\Get(
     *   path="/appearances",
     *   description="Allows querying the full library of public appearances with forced pagination",
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
            'guide' => ['required', new Enum(GuideName::class)],
            'size' => 'sometimes|numeric|between:7,20',
            'q' => 'sometimes|string|nullable',
            'page' => 'sometimes|required|int|min:1',
        ])->validate();

        $guide_name = GuideName::from($valid['guide']);
        $appearances_per_page = $valid['size'] ?? UserPrefHelper::get(
            $request->user(),
            UserPrefKey::ColorGuide_ItemsPerPage
        );
        $query = !empty($valid['q']) ? $valid['q'] : null;
        $page = $valid['page'] ?? 1;
        $pagination = ColorGuideHelper::searchGuide($page, $appearances_per_page, $guide_name, $query);
        $results = $pagination->getCollection()->map(fn (Appearance $a) => ColorGuideHelper::mapAppearance($a));
        return response()->camelJson([
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
     *     name="sort",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/FullGuideSortField"),
     *     description="Determines how the results will be sorted"
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="OK",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/SlimAppearanceList"),
     *         @OA\Schema(ref="#/components/schemas/GuideFullListGroups")
     *       }
     *     )
     *   )
     * )
     * @param  Request  $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function queryFullPublic(Request $request)
    {
        $valid = Validator::make($request->all(), [
            'guide' => ['required', new Enum(GuideName::class)],
            'sort' => [new Enum(FullGuideSortField::class)],
        ])->validate();

        $guide_name = GuideName::from($valid['guide']);
        $sort = !empty($valid['sort']) ? FullGuideSortField::from($valid['sort']) : FullGuideSortField::Relevance;

        /**
         * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $builder
         * @return \Illuminate\Support\Collection|Appearance[]
         */
        $get_by_guide = fn ($builder) => $builder->where('guide', $guide_name)->where('id', '!=', 0)->get();

        switch ($sort) {
            case FullGuideSortField::Relevance:
                $appearances = $get_by_guide(Appearance::ordered()->with('tags.synonymTo'));
                break;
            case FullGuideSortField::Alphabetically:
                $appearances = $get_by_guide(Appearance::orderBy('label'));
                break;
            case FullGuideSortField::DateAdded:
                $appearances = $get_by_guide(Appearance::orderByDesc('created_at'));
                break;
            default:
                throw new \RuntimeException("Unhandled sort field {$sort->value}");
        }

        $results = $appearances->map(fn (Appearance $a) => ColorGuideHelper::mapAppearance($a, true));

        return response()->camelJson([
            'appearances' => $results,
            'groups' => ColorGuideHelper::createGroupsForFullList($guide_name, $sort, $appearances),
        ]);
    }

    /**
     * @OA\Get(
     *   path="/appearances/{id}",
     *   description="Get all relevant information about a sn appearance at once, including tags, color groups and relations (heavy)",
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
     *     description="Complete appearance information",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/DetailedAppearance")
     *       }
     *     )
     *   ),
     *   @OA\Response(
     *     response="404",
     *     description="Appearance not found or has been deleted",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *       }
     *     )
     *   )
     * )
     * @param  Request  $request
     * @param  Appearance  $appearance
     * @return JsonResponse
     */
    public function get(Request $request, Appearance $appearance)
    {
        if ($error = self::_handlePrivateAppearanceCheck($request, $appearance)) {
            return $error;
        }

        return response()->camelJson(ColorGuideHelper::mapDetailedAppearance($appearance));
    }

    private static function _handlePrivateAppearanceCheck(Request $request, Appearance $appearance): ?JsonResponse
    {
        if ($appearance->private && Permission::insufficient(Role::Staff)) {
            /** @var User $user */
            $user = Auth::user();
            if ($user && $appearance->owner_id === $user->id) {
                return null;
            }

            // TODO Check token parameter and allow if matches

            return response()->camelJson(['message' => trans('errors.color_guide.appearance_private')], 403);
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
    public function colorGroups(Request $request, Appearance $appearance)
    {
        if ($error = self::_handlePrivateAppearanceCheck($request, $appearance)) {
            return $error;
        }

        return response()->camelJson(['colorGroups' => ColorGuideHelper::getColorGroups($appearance)]);
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
     *     required=false,
     *     @OA\Schema(ref="#/components/schemas/SpriteSize")
     *   ),
     *   @OA\Parameter(
     *     in="query",
     *     name="token",
     *     required=false,
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
     *     @OA\Header(
     *       header="Location",
     *       @OA\Schema(ref="#/components/schemas/LocationHeader"),
     *     ),
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

        $params = Validator::make($request->only('size'), [
            'size' => ['required', 'integer', new Enum(SpriteSize::class)],
        ])->valid();
        $double_size = isset($params['size']) && $params['size'] === SpriteSize::Double;

        $sprite_file = $appearance->spriteFile();
        if ($sprite_file === null) {
            return response()->noContent(404);
        }

        // For private appearances, respond with the sprite URL
        if ($appearance->is_private) {
            $sprite_path = $double_size
                ? $sprite_file->getPath(Appearance::DOUBLE_SIZE_CONVERSION)
                : $sprite_file->getPath();
            return response()->file($sprite_path, ['cache-control' => 'private, must-revalidate']);
        }

        // For public appearances, redirect to cached public location
        $sprite_data = ColorGuideHelper::mapSprite($appearance, $double_size, $sprite_file);
        return redirect($sprite_data['path']);
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

    /**
     * @OA\Get(
     *   path="/appearances/pinned",
     *   description="Get list of pinned appearances for a specific guide",
     *   tags={"appearances"},
     *   security={},
     *   @OA\Parameter(
     *     in="query",
     *     name="guide",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/GuideName")
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="The list of pinned appearances",
     *     @OA\JsonContent(
     *       additionalProperties=false,
     *       type="array",
     *       minItems=0,
     *       @OA\Items(ref="#/components/schemas/Appearance")
     *     )
     *   ),
     *   @OA\Response(
     *     response="422",
     *     description="Invalid guide name",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *       }
     *     )
     *   )
     * )
     * @param  Request  $request
     * @return JsonResponse|Response
     */
    public function pinned(Request $request)
    {
        $valid = Validator::make($request->all(), [
            'guide' => ['required', new Enum(GuideName::class)],
        ])->validate();

        $pinned_appearances = PinnedAppearance::where('guide', $valid['guide'])
            ->with('appearance')
            ->get()
            ->map(fn (PinnedAppearance $pinned) => ColorGuideHelper::mapAppearance($pinned->appearance));

        return response()->camelJson($pinned_appearances);
    }

    /**
     * @OA\Get(
     *   path="/appearances/autocomplete",
     *   description="Get list of pinned appearances for a specific guide",
     *   tags={"appearances"},
     *   security={},
     *   @OA\Parameter(
     *     in="query",
     *     name="guide",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/GuideName")
     *   ),
     *   @OA\Parameter(
     *     in="query",
     *     name="q",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/QueryString"),
     *     description="Search query"
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="List of matching appearances",
     *     @OA\JsonContent(
     *       additionalProperties=false,
     *       type="array",
     *       minItems=0,
     *       @OA\Items(ref="#/components/schemas/AutocompleteAppearance")
     *     )
     *   ),
     *   @OA\Response(
     *     response="422",
     *     description="Invalid guide name",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *       }
     *     )
     *   )
     * )
     * @param  Request  $request
     * @return JsonResponse|Response
     */
    public function autocomplete(Request $request)
    {
        $valid = Validator::make($request->all(), [
            'guide' => ['required', new Enum(GuideName::class)],
            'q' => 'sometimes|string|nullable',
        ])->validate();

        $guide_name = GuideName::from($valid['guide']);
        $query = !empty($valid['q']) ? $valid['q'] : null;
        $page = 1;
        $autocomplete_count = 5;
        $pagination = ColorGuideHelper::searchGuide($page, $autocomplete_count, $guide_name, $query);
        $results = $pagination->getCollection()->map(fn (
            Appearance $a
        ) => ColorGuideHelper::mapAutocompleteAppearance($a));
        return response()->camelJson($results);
    }

    /**
     * @OA\Get(
     *   path="/appearances/{id}/locate",
     *   description="Find out the guide and label for an appearance using its ID",
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
     *     description="Bare appearance information",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/PreviewAppearance")
     *       }
     *     )
     *   ),
     *   @OA\Response(
     *     response="404",
     *     description="Appearance not found or has been deleted",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *       }
     *     )
     *   )
     * )
     * @param  Request  $request
     * @return JsonResponse|Response
     */
    public function locate(Request $request, Appearance $appearance)
    {
        if ($error = self::_handlePrivateAppearanceCheck($request, $appearance)) {
            return $error;
        }

        return response()->camelJson(ColorGuideHelper::mapPreviewAppearance($appearance));
    }
}
