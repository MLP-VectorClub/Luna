<?php


namespace App\Http\Controllers;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Models\Appearance;
use App\Models\MajorChange;
use App\Utils\ColorGuideHelper;
use App\Utils\Core;
use App\Utils\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use OpenApi\Attributes as OA;

class ColorGuideController extends Controller
{
    /**
     * @return \Illuminate\Http\JsonResponse
     */
    #[OA\Schema(
        schema: 'MajorChangeList',
        type: 'object',
        description: 'An array of major change items under the changes key',
        required: ['changes'],
        additionalProperties: false,
        properties: [
            new OA\Property(
                property: 'changes',
                type: 'array',
                items: new OA\Items(ref: '#/components/schemas/MajorChange'),
            ),
        ],
    )]
    #[OA\Get(
        path: '/color-guide',
        description: 'Get data about the color guides available on the server',
        tags: ['color guide'],
        security: [],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['entryCounts'],
                    additionalProperties: false,
                    properties: [
                        new OA\Property(property: 'entryCounts', ref: '#/components/schemas/GuideEntryCounts'),
                    ],
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'The application server is currently unavailable, more information may be in the request body',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')]),
            ),
        ],
    )]
    public function index()
    {
        $entry_counts = array_reduce(GuideName::values(), function (array $acc, string $value) {
            $acc[$value] = Appearance::where('guide', $value)->count();
            return $acc;
        }, []);

        return response()->camelJson([
            'entry_counts' => $entry_counts,
        ]);
    }

    /**
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    #[OA\Schema(
        schema: 'GuideMajorChangesPageSize',
        type: 'integer',
        minimum: 1,
        maximum: 15,
        default: 9,
        description: 'The number of results to return per page',
    )]
    #[OA\Get(
        path: '/color-guide/major-changes',
        description: 'Get a list of major changes in the provided guide',
        tags: ['color guide'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'query',
                name: 'guide',
                required: true,
                description: 'Determines the guide to return results for',
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
                schema: new OA\Schema(ref: '#/components/schemas/GuideMajorChangesPageSize'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: '#/components/schemas/MajorChangeList'),
                        new OA\Schema(ref: '#/components/schemas/PageData'),
                    ],
                ),
            ),
            new OA\Response(
                response: '503',
                description: 'The application server is currently unavailable, more information may be in the request body',
                content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')]),
            ),
        ],
    )]
    public function majorChanges(Request $request)
    {
        $valid = Validator::make($request->all(), [
            'guide' => ['required', new Enum(GuideName::class)],
            'size' => 'sometimes|numeric|between:1,15',
            'page' => 'sometimes|required|int|min:1',
        ])->validate();

        $query = MajorChange::with('appearance');
        $is_staff = Permission::sufficient(Role::Staff);
        if ($is_staff) {
            $query = $query->with('user');
        }

        $guide = GuideName::from($valid['guide']);
        $page = $valid['page'] ?? 1;
        $changes_per_page = $valid['size'] ?? 9;
        $pagination = $query
            ->join('appearances', 'major_changes.appearance_id', '=', 'appearances.id')
            ->where('appearances.guide', $guide)
            ->orderByDesc('major_changes.created_at')
            ->paginate($changes_per_page, ['major_changes.*']);

        $changes = Collection::make($pagination->items())
            ->map(fn (MajorChange $change) => ColorGuideHelper::mapMajorChange($change, $is_staff));

        return response()->camelJson([
            'changes' => $changes,
            'pagination' => Core::mapPagination($pagination),
        ]);
    }
}
