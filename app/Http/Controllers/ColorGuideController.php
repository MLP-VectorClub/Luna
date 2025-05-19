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
use OpenApi\Annotations as OA;

class ColorGuideController extends Controller
{
    /**
     * @OA\Schema(
     *   schema="MajorChangeList",
     *   type="object",
     *   description="An array of major change items under the changes key",
     *   required={
     *     "changes"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="changes",
     *     type="array",
     *     @OA\Items(ref="#/components/schemas/MajorChange")
     *   )
     * )
     * @OA\Get(
     *   path="/color-guide",
     *   description="Get data about the color guides available on the server",
     *   tags={"color guide"},
     *   security={},
     *   @OA\Response(
     *     response="200",
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       required={
     *         "entryCounts",
     *       },
     *       additionalProperties=false,
     *       @OA\Property(
     *         property="entryCounts",
     *         ref="#/components/schemas/GuideEntryCounts"
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response="503",
     *     description="The application server is currently unavailable, more information may be in the request body",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *       }
     *     )
     *   )
     * )
     *
     * @return \Illuminate\Http\JsonResponse
     */
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
     * @OA\Schema(
     *   schema="GuideMajorChangesPageSize",
     *   type="integer",
     *   minimum=1,
     *   maximum=15,
     *   default=9,
     *   description="The number of results to return per page"
     * )
     * @OA\Get(
     *   path="/color-guide/major-changes",
     *   description="Get a list of major changes in the provided guide",
     *   tags={"color guide"},
     *   security={},
     *   @OA\Parameter(
     *     in="query",
     *     name="guide",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/GuideName"),
     *     description="Determines the guide to return results for"
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
     *     @OA\Schema(ref="#/components/schemas/GuideMajorChangesPageSize"),
     *     description="The number of results to return per page"
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="OK",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/MajorChangeList"),
     *         @OA\Schema(ref="#/components/schemas/PageData")
     *       }
     *     )
     *   ),
     *   @OA\Response(
     *     response="503",
     *     description="The application server is currently unavailable, more information may be in the request body",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *       }
     *     )
     *   )
     * )
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
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
