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
     *   schema="ShowListItem",
     *   type="object",
     *   description="Represents a show entry for showing in paginated lists, only containing the essential properties",
     *   additionalProperties=false,
     *   required={
     *     "id",
     *     "type",
     *     "title",
     *     "season",
     *     "episode",
     *     "parts",
     *     "no",
     *     "generation",
     *     "airs",
     *   },
     *   @OA\Property(
     *     property="id",
     *     type="number",
     *     ref="#/components/schemas/OneBasedId",
     *     example=1,
     *     description="Unique identifier for the show entry i nthe database",
     *   ),
     *   @OA\Property(
     *     property="type",
     *     ref="#/components/schemas/ShowType",
     *     example="episode",
     *     description="Type of the show entry",
     *   ),
     *   @OA\Property(
     *     property="title",
     *     type="string",
     *     example="Slice of Life",
     *     description="Title of the entry, optionally including prefixes denoting the franchise (e.g. Equestria Girls)",
     *   ),
     *   @OA\Property(
     *     property="season",
     *     type="integer",
     *     nullable=true,
     *     example=5,
     *     description="Season number, `null` for any types other than `episode`",
     *   ),
     *   @OA\Property(
     *     property="episode",
     *     type="integer",
     *     nullable=true,
     *     example=9,
     *     description="Episode number of the first episode this entry represents, `null` for any types other than `episode`. See `parts` for more information.",
     *   ),
     *   @OA\Property(
     *     property="parts",
     *     type="integer",
     *     nullable=true,
     *     example=1,
     *     description="Indicates how many parts this entry represents, used for displaying rangesfor two part entries, e.g. the episodes S1E1-2 would be represented as one entry with `{ season: 1, episode: 1, parts: 2, ... }`, while `null` or `1` indicates the entry represents a single episode.",
     *   ),
     *   @OA\Property(
     *     property="no",
     *     type="integer",
     *     nullable=true,
     *     example=100,
     *     description="Overall number placing entries in a coherent order relative to each other (not a fixed value)\n\nFor episodes this is the overall episode number, for all other entry types this is mostly a sequential value incremented for each new entry.",
     *   ),
     *   @OA\Property(
     *     property="generation",
     *     ref="#/components/schemas/MlpGeneration",
     *     nullable=true,
     *     example="pony",
     *     description="Which generation of the MLP franchise this entry belongs to. `null` for non-episode entries.",
     *   ),
     *   @OA\Property(
     *     property="airs",
     *     ref="#/components/schemas/IsoStandardDate",
     *     nullable=true,
     *     description="Represents the timestamp of when the entry aired officially, typically this is official the U.S. air date on TV for episodes or the day of the theatrical U.S. release for movies. This is mostly for informational purposes and should not be relied on for accuracy.",
     *   ),
     * )
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
            'generation' => $show->generation,
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
     *     description="What method to use for ordering results. Overall sorting is based only on the `no` field (default), while series sorting is meant for episodes and uses the `generation`, `season` and `episode` fields to keep them in chronological order."
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
            'types' => 'required|array|min:1',
            'types.*' => ['string', new Enum(ShowType::class)],
            'order' =>  ['required', 'string', new Enum(ShowOrdering::class)],
            'page' => 'sometimes|required|int|min:1',
            'size' => 'sometimes|numeric|between:1,10',
        ])->validate();

        $per_page = $valid['size'] ?? 8;

        $query = Show::whereIn('type', $valid['types']);

        switch ($valid['order']) {
            case ShowOrdering::Series:
                $query = $query->orderBy('generation', 'desc')->orderBy('season', 'desc')->orderBy('episode', 'desc');
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
