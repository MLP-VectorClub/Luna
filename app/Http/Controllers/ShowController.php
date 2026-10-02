<?php

namespace App\Http\Controllers;

use App\Enums\ShowOrdering;
use App\Enums\ShowType;
use App\Models\Show;
use App\Utils\Core;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;

class ShowController extends Controller
{
    /**
     * @OA\Schema(
     *   schema="ShowList",
     *   type="object",
     *   description="An array of public show entries under the show key",
     *   required={
     *     "show"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="show",
     *     type="array",
     *     @OA\Items(ref="#/components/schemas/ShowListItem")
     *   )
     * )
     * @param  Show  $show
     * @return array
     */
    public static function mapShowListItem(Show $show)
    {
        return [
            'id' => $show->id,
            'type' => $show->type,
            'title' => $show->title,
            'season' => $show->season,
            'episode' => $show->episode,
            'parts' => $show->parts,
            'no' => $show->no,
            'airs' => $show->airs !== null ? $show->airs->toISOString() : null,
        ];
    }

    /**
     * @OA\Schema(
     *   schema="ShowListPageSize",
     *   type="integer",
     *   minimum=1,
     *   maximum=10,
     *   default=8,
     *   description="The number of results to return per page"
     * )
     * @OA\Get(
     *   path="/show",
     *   description="Allows querying all show entries with forced pagination",
     *   tags={"show"},
     *   security={},
     *   @OA\Parameter(
     *     in="query",
     *     name="types[]",
     *     required=true,
     *     @OA\Schema(
     *       type="array",
     *       minItems=1,
     *       @OA\Items(ref="#/components/schemas/ShowType")
     *     ),
     *     description="List of types of entries to return",
     *   ),
     *   @OA\Parameter(
     *     in="query",
     *     name="order",
     *     required=false,
     *     @OA\Schema(ref="#/components/schemas/ShowOrdering"),
     *     description="What method to use for ordering results. Overall sorting is based only on the `no` field (default), while series sorting is meant for episodes and uses the `season` and `episode` fields to keep them in chronological order."
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
     *     @OA\Schema(ref="#/components/schemas/ShowListPageSize"),
     *     description="The number of results to return per page"
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="OK",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ShowList"),
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
    public function index(Request $request)
    {
        $valid = Validator::make($request->all(), [
            'types' => ['required', 'array', 'min:1', function ($attribute, $value, $fail) {
                foreach ($value as $type) {
                    if (!is_string($type) || ShowType::tryFrom($type) === null) {
                        $fail('The selected types are invalid.');
                        return;
                    }
                }
            }],
            'order' =>  ['required', 'string', new Enum(ShowOrdering::class)],
            'page' => 'sometimes|required|int|min:1',
            'size' => 'sometimes|numeric|between:1,10',
            'season' => 'sometimes|integer|min:0',
            'episode' => 'sometimes|integer|min:0',
        ])->validate();

        $per_page = $valid['size'] ?? 8;

        $query = Show::whereIn('type', $valid['types']);
        foreach (['season', 'episode'] as $column) {
            if (isset($valid[$column])) {
                $query = $query->where($column, $valid[$column]);
            }
        }

        switch ($valid['order']) {
            case ShowOrdering::Series:
                $query = $query->orderBy('season', 'desc')->orderBy('episode', 'desc');
                break;
            case ShowOrdering::Overall:
            default:
                $query = $query->orderBy('no', 'desc');
        }

        $pagination = $query->paginate($per_page);

        return response()->camelJson([
            'show' => $pagination->map(fn (Show $show) => self::mapShowListItem($show)),
            'pagination' => Core::mapPagination($pagination),
        ]);
    }
}
