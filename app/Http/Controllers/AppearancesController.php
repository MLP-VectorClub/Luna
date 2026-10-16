<?php

namespace App\Http\Controllers;

use App\Enums\FullGuideSortField;
use App\Enums\GuideName;
use App\Enums\Role;
use App\Enums\SpriteSize;
use App\Enums\UserPrefKey;
use App\Models\Appearance;
use App\Models\Tag;
use App\Models\PinnedAppearance;
use App\Models\User;
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
use OpenApi\Attributes as OA;

class AppearancesController extends Controller
{
    /**
     * @param  Request  $request
     * @return JsonResponse|Response
     * @throws ValidationException
     */
    #[OA\Schema(
        schema: 'ListOfColorGroups',
        type: 'object',
        description: 'Array of color groups under the `colorGroups` key',
        required: ['colorGroups'],
        additionalProperties: false,
        properties: [
            new OA\Property(
                property: 'colorGroups',
                type: 'array',
                minItems: 0,
                description: 'Array of color groups belonging to an appearance (may be an empty array).',
                items: new OA\Items(ref: '#/components/schemas/ColorGroup'),
            ),
        ],
    )]
    #[OA\Get(
        path: '/appearances',
        description: 'Allows querying the full library of public appearances with forced pagination',
        tags: ['appearances'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'query',
                name: 'guide',
                required: true,
                description: 'Determines the guide to search in',
                schema: new OA\Schema(ref: '#/components/schemas/GuideName'),
            ),
            new OA\Parameter(
                in: 'query',
                name: 'page',
                required: false,
                description: 'Which page of results to return',
                schema: new OA\Schema(ref: '#/components/schemas/PageNumber'),
            ),
            new OA\Parameter(
                in: 'query',
                name: 'size',
                required: false,
                description: 'The number of results to return per page',
                schema: new OA\Schema(ref: '#/components/schemas/GuidePageSize'),
            ),
            new OA\Parameter(
                in: 'query',
                name: 'q',
                required: false,
                description: 'Search query',
                schema: new OA\Schema(ref: '#/components/schemas/QueryString'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: '#/components/schemas/AppearanceList'),
                        new OA\Schema(ref: '#/components/schemas/PageData'),
                    ],
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'Temporarily Unavailable',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')]),
            ),
        ],
    )]
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
     * @param  Request  $request
     * @return JsonResponse
     * @throws ValidationException
     */
    #[OA\Get(
        path: '/appearances/full',
        description: 'Get a list of every appearance in the database (without color group data)',
        tags: ['appearances'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'query',
                name: 'guide',
                required: true,
                description: 'Determines the guide to search in',
                schema: new OA\Schema(ref: '#/components/schemas/GuideName'),
            ),
            new OA\Parameter(
                in: 'query',
                name: 'sort',
                required: true,
                description: 'Determines how the results will be sorted',
                schema: new OA\Schema(ref: '#/components/schemas/FullGuideSortField'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: '#/components/schemas/SlimAppearanceList'),
                        new OA\Schema(ref: '#/components/schemas/GuideFullListGroups'),
                    ],
                ),
            ),
        ],
    )]
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

        // The other names of characters (synonyms of their tags), for the "AKA" of the list items
        $character_synonyms = Tag::where('type', 'char')->whereNotNull('synonym_of')->get()->groupBy('synonym_of');
        $results = $appearances->map(fn (Appearance $a) => ColorGuideHelper::mapAppearance($a, true, false, $character_synonyms));

        return response()->camelJson([
            'appearances' => $results,
            'groups' => ColorGuideHelper::createGroupsForFullList($guide_name, $sort, $appearances),
        ]);
    }

    /**
     * @param  Request  $request
     * @param  Appearance  $appearance
     * @return JsonResponse
     */
    #[OA\Get(
        path: '/appearances/{id}',
        description: 'Get all relevant information about a sn appearance at once, including tags, color groups and relations (heavy)',
        tags: ['appearances'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/ZeroBasedId'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete appearance information',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/DetailedAppearance')]),
            ),
            new OA\Response(
                response: '404',
                description: 'Appearance not found or has been deleted',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')]),
            ),
        ],
    )]
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

            if ($appearance->tokenMatches($request->query('token'))) {
                return null;
            }

            return response()->camelJson(['message' => trans('errors.color_guide.appearance_private')], 403);
        }

        return null;
    }

    /**
     * @param  Request  $request
     * @param  Appearance  $appearance
     * @return JsonResponse|Response
     */
    #[OA\Get(
        path: '/appearances/{id}/color-groups',
        description: 'Get all color groups associated with an appearance',
        tags: ['appearances'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/ZeroBasedId'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ListOfColorGroups')]),
            ),
        ],
    )]
    public function colorGroups(Request $request, Appearance $appearance)
    {
        if ($error = self::_handlePrivateAppearanceCheck($request, $appearance)) {
            return $error;
        }

        return response()->camelJson(['colorGroups' => ColorGuideHelper::getColorGroups($appearance)]);
    }

    /**
     * @param  Request  $request
     * @param  Appearance  $appearance
     */
    #[OA\Schema(schema: 'AppearanceToken', type: 'string', format: 'uuid')]
    #[OA\Schema(
        schema: 'LocationHeader',
        description: 'Contains a URL that most clients will automatically redirect to for 301 and 302 responses',
        type: 'string',
        format: 'URL',
    )]
    #[OA\Get(
        path: '/appearances/{id}/sprite',
        description: 'Fetch the sprite file associated with the appearance',
        tags: ['appearances'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/ZeroBasedId'),
            ),
            new OA\Parameter(
                in: 'query',
                name: 'size',
                required: false,
                schema: new OA\Schema(ref: '#/components/schemas/SpriteSize'),
            ),
            new OA\Parameter(
                in: 'query',
                name: 'token',
                required: false,
                schema: new OA\Schema(ref: '#/components/schemas/AppearanceToken'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'The sprite image data (if the appearance is private)',
                content: new OA\MediaType(
                    mediaType: 'image/png',
                    schema: new OA\Schema(ref: '#/components/schemas/File'),
                ),
            ),
            new OA\Response(
                response: '302',
                description: 'Redirect to the current sprite image URL (if the appearance is public).',
                headers: [
                    new OA\Header(
                        header: 'Location',
                        schema: new OA\Schema(ref: '#/components/schemas/LocationHeader'),
                    ),
                ],
            ),
            new OA\Response(
                response: '404',
                description: 'Sprite image missing',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')]),
            ),
            new OA\Response(
                response: '403',
                description: 'You don\'t have permission to access this resource',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')]),
            ),
        ],
    )]
    public function sprite(Request $request, Appearance $appearance)
    {
        if ($error = self::_handlePrivateAppearanceCheck($request, $appearance)) {
            return $error;
        }

        $params = Validator::make($request->only('size'), [
            'size' => ['required', 'integer', new Enum(SpriteSize::class)],
        ])->valid();
        $double_size = isset($params['size']) && (int) $params['size'] === SpriteSize::Double->value;

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
     * @param  Request  $request
     * @param  Appearance  $appearance
     * @return JsonResponse|Response
     */
    #[OA\Get(
        path: '/appearances/{id}/preview',
        description: 'Fetch the preview file associated with the appearance',
        tags: ['appearances'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/ZeroBasedId'),
            ),
            new OA\Parameter(
                in: 'query',
                name: 'token',
                schema: new OA\Schema(ref: '#/components/schemas/AppearanceToken'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'The appearance preview image',
                content: new OA\MediaType(
                    mediaType: 'image/svg+xml',
                    schema: new OA\Schema(ref: '#/components/schemas/SVGFile'),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Appearance missing',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')]),
            ),
            new OA\Response(
                response: '403',
                description: 'You don\'t have permission to access this resource',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')]),
            ),
        ],
    )]
    public function preview(Request $request, Appearance $appearance)
    {
        if ($error = self::_handlePrivateAppearanceCheck($request, $appearance)) {
            return $error;
        }

        // TODO CGUtils::renderPreviewSVG($appearance);
        return response()->noContent(404);
    }

    /**
     * @param  Request  $request
     * @return JsonResponse|Response
     */
    #[OA\Get(
        path: '/appearances/pinned',
        description: 'Get list of pinned appearances for a specific guide',
        tags: ['appearances'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'query',
                name: 'guide',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/GuideName'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'The list of pinned appearances',
                content: new OA\JsonContent(
                    additionalProperties: false,
                    type: 'array',
                    minItems: 0,
                    items: new OA\Items(ref: '#/components/schemas/Appearance'),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid guide name',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')]),
            ),
        ],
    )]
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
     * @param  Request  $request
     * @return JsonResponse|Response
     */
    #[OA\Get(
        path: '/appearances/autocomplete',
        description: 'Get list of pinned appearances for a specific guide',
        tags: ['appearances'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'query',
                name: 'guide',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/GuideName'),
            ),
            new OA\Parameter(
                in: 'query',
                name: 'q',
                required: true,
                description: 'Search query',
                schema: new OA\Schema(ref: '#/components/schemas/QueryString'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of matching appearances',
                content: new OA\JsonContent(
                    additionalProperties: false,
                    type: 'array',
                    minItems: 0,
                    items: new OA\Items(ref: '#/components/schemas/AutocompleteAppearance'),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid guide name',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')]),
            ),
        ],
    )]
    public function autocomplete(Request $request)
    {
        $valid = Validator::make($request->all(), [
            'guide' => ['required', new Enum(GuideName::class)],
            'q' => 'sometimes|string|nullable',
        ])->validate();

        $guide_name = GuideName::from($valid['guide']);
        $query = !empty($valid['q']) ? $valid['q'] : null;
        if ($query === null) {
            return response()->json([]);
        }
        $page = 1;
        $autocomplete_count = 5;
        $pagination = ColorGuideHelper::searchGuide($page, $autocomplete_count, $guide_name, $query);
        $results = $pagination->getCollection()->map(fn (
            Appearance $a
        ) => ColorGuideHelper::mapAutocompleteAppearance($a));
        return response()->camelJson($results);
    }

    /**
     * @param  Request  $request
     * @return JsonResponse|Response
     */
    #[OA\Get(
        path: '/appearances/{id}/locate',
        description: 'Find out the guide and label for an appearance using its ID',
        tags: ['appearances'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/ZeroBasedId'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Bare appearance information',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/PreviewAppearance')]),
            ),
            new OA\Response(
                response: '404',
                description: 'Appearance not found or has been deleted',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')]),
            ),
        ],
    )]
    public function locate(Request $request, Appearance $appearance)
    {
        if ($error = self::_handlePrivateAppearanceCheck($request, $appearance)) {
            return $error;
        }

        return response()->camelJson(ColorGuideHelper::mapPreviewAppearance($appearance));
    }
}
