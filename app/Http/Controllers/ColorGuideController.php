<?php


namespace App\Http\Controllers;

use App\Enums\GuideName;
use App\Models\Appearance;
use OpenApi\Annotations as OA;

class ColorGuideController extends Controller
{
    /**
     * @OA\Get(
     *   path="/color-guides",
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
        $entry_counts = array_reduce(GuideName::getValues(), function (array $acc, string $value) {
            $acc[$value] = Appearance::where('guide', $value)->count();
            return $acc;
        }, []);

        return response()->json([
            'entry_counts' => $entry_counts,
        ]);
    }
}
