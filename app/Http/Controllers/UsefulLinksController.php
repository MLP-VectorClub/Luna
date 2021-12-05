<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\UsefulLink;
use App\Models\User;
use App\Utils\Core;
use App\Utils\GitHelper;
use App\Utils\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use OpenApi\Annotations as OA;

class UsefulLinksController extends Controller
{
    protected function mapUsefulLink(UsefulLink $usefulLink): array
    {
        return $usefulLink->toArray();
    }

    /**
     * @OA\Schema(
     *   schema="PublicUsefulLink",
     *   description="Contains publicly accessible properties of useful links",
     *   type="object",
     *   required={
     *     "id",
     *     "url",
     *     "label",
     *     "order",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="id",
     *     ref="#/components/schemas/OneBasedId"
     *   ),
     *   @OA\Property(
     *     property="url",
     *     type="string",
     *     format="url",
     *     description="The URL this link points to",
     *     example="/cg/color-picker"
     *   ),
     *   @OA\Property(
     *     property="label",
     *     type="string",
     *     description="The link text to display on the page",
     *     example="Color Picker",
     *   ),
     *   @OA\Property(
     *     property="title",
     *     type="string",
     *     description="The title text associated with the link providing additional context about why it's useful",
     *     example="Use this to get averaged color values from multiple images",
     *   ),
     *   @OA\Property(
     *     property="order",
     *     ref="#/components/schemas/Order"
     *   )
     * )
     * @OA\Schema(
     *   schema="UsefulLink",
     *   description="Contains all stored properties of useful links",
     *   allOf={
     *     @OA\Schema(ref="#/components/schemas/PublicUsefulLink"),
     *     @OA\Schema(
     *       type="object",
     *       required={
     *         "minrole",
     *       },
     *       additionalProperties=false,
     *       @OA\Property(
     *         property="minrole",
     *         type="string",
     *         description="The minimum role required to be able to see this link in application the sidebar",
     *         example="staff",
     *       )
     *     )
     *   }
     * )
     * @OA\Get(
     *   path="/useful-links/sidebar",
     *   description="Get the list of useful links available to the user for display in the sidebar",
     *   tags={"useful links"},
     *   security={},
     *   @OA\Response(
     *     response="200",
     *     description="OK",
     *     @OA\JsonContent(
     *       type="array",
     *       minItems=0,
     *       @OA\Items(ref="#/components/schemas/PublicUsefulLink")
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
     */
    public function sidebar()
    {
        // Logged out users will not see useful links
        if (!Auth::check()) {
            return response()->json([]);
        }

        /** @var $user User */
        $user = Auth::user();
        $available_roles = array_filter(Role::cases(), fn ($el) => Permission::sufficient($el, $user->role));
        $available_roles[] = 'guest';

        $links = UsefulLink::ordered()->whereIn('minrole', $available_roles)->get()
            ->map(fn (UsefulLink $usefulLink) => $this->mapUsefulLink($usefulLink));

        return response()->camelJson($links);
    }
}
