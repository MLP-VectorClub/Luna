<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\UsefulLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;

class UsefulLinksController extends Controller
{
    /**
     * Roles a link can be restricted to, `guest` means every signed in visitor
     */
    private static function minRoles(): array
    {
        return ['guest', ...array_map(fn(Role $role) => $role->value, Role::cases())];
    }

    private static function sidebarItem(UsefulLink $link): array
    {
        return [
            'id' => $link->id,
            'label' => $link->label,
            'url' => $link->url,
            'title' => $link->title,
            'minRole' => $link->minrole,
        ];
    }

    private static function visibleTo(UsefulLink $link, Role $role): bool
    {
        return $link->minrole === 'guest' || perm(Role::from($link->minrole), $role);
    }

    /**
     * @OA\Schema(
     *   schema="SidebarUsefulLink",
     *   description="A useful link as listed in the sidebar and in the management list",
     *   type="object",
     *   required={"id", "label", "url", "minRole"},
     *   additionalProperties=false,
     *   @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *   @OA\Property(property="label", type="string", description="The link text to display on the page", example="Color Picker"),
     *   @OA\Property(property="url", type="string", description="The URL this link points to", example="/cg/color-picker"),
     *   @OA\Property(property="title", type="string", nullable=true, description="Additional context about why the link is useful"),
     *   @OA\Property(property="minRole", type="string", description="The lowest role that can see this link, `guest` is every signed in user")
     * )
     * @OA\Schema(
     *   schema="UsefulLink",
     *   type="object",
     *   required={"label", "url", "title", "minRole"},
     *   additionalProperties=false,
     *   @OA\Property(property="label", type="string", minLength=3, maxLength=35),
     *   @OA\Property(property="url", type="string", minLength=3, maxLength=255),
     *   @OA\Property(property="title", type="string", maxLength=255),
     *   @OA\Property(property="minRole", type="string")
     * )
     * @OA\Schema(
     *   schema="UsefulLinkInput",
     *   description="Used to create or update a useful link. `title` is optional and defaults to an empty string",
     *   type="object",
     *   required={"label", "url", "minRole"},
     *   additionalProperties=false,
     *   @OA\Property(property="label", type="string", minLength=3, maxLength=35),
     *   @OA\Property(property="url", type="string", minLength=3, maxLength=255),
     *   @OA\Property(property="title", type="string", maxLength=255),
     *   @OA\Property(property="minRole", type="string")
     * )
     * @OA\Get(
     *   path="/useful-links/sidebar",
     *   operationId="GetUsefulLinksSidebar",
     *   description="Get the list of useful links available to the user for display in the sidebar. Signed-out visitors get an empty list",
     *   tags={"useful links"},
     *   security={},
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/SidebarUsefulLink")))
     * )
     */
    public function sidebar(Request $request): JsonResponse
    {
        $user = $request->user();
        // Logged out users will not see useful links
        if ($user === null) {
            return response()->json([]);
        }

        return response()->json(
            UsefulLink::ordered()->get()
                ->filter(fn(UsefulLink $link) => self::visibleTo($link, $user->role))
                ->map(fn(UsefulLink $link) => self::sidebarItem($link))
                ->values()
        );
    }

    /**
     * @OA\Get(
     *   path="/useful-links",
     *   operationId="GetUsefulLinks",
     *   description="Every useful link in display order, whatever role it is meant for, for managing them. Staff only",
     *   tags={"useful links"},
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/SidebarUsefulLink"))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(): JsonResponse
    {
        return response()->json(UsefulLink::ordered()->get()->map(fn(UsefulLink $link) => self::sidebarItem($link))->values());
    }

    /**
     * @OA\Get(
     *   path="/useful-links/{id}",
     *   operationId="GetUsefulLinksId",
     *   description="Get the details of a useful link. Requires staff role",
     *   tags={"useful links"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/UsefulLink")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(int $id): JsonResponse
    {
        $link = UsefulLink::findOrFail($id);

        return response()->json([
            'label' => $link->label,
            'url' => $link->url,
            'title' => $link->title,
            'minRole' => $link->minrole,
        ]);
    }

    /**
     * @OA\Post(
     *   path="/useful-links",
     *   operationId="PostUsefulLinks",
     *   description="Create a new useful link. Requires staff role",
     *   tags={"useful links"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/UsefulLinkInput")),
     *   @OA\Response(response="201", description="Created", @OA\JsonContent(type="object", required={"id"}, @OA\Property(property="id", ref="#/components/schemas/OneBasedId"))),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function create(Request $request): JsonResponse
    {
        $link = new UsefulLink($this->validated($request));
        $link->save();

        return response()->json(['id' => $link->id], 201);
    }

    /**
     * @OA\Put(
     *   path="/useful-links/{id}",
     *   operationId="PutUsefulLinksId",
     *   description="Update an existing useful link. Requires staff role",
     *   tags={"useful links"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/UsefulLinkInput")),
     *   @OA\Response(response="204", description="Updated"),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(Request $request, int $id): Response
    {
        $link = UsefulLink::findOrFail($id);
        $link->update($this->validated($request));

        return response()->noContent();
    }

    /**
     * @OA\Delete(
     *   path="/useful-links/{id}",
     *   operationId="DeleteUsefulLinksId",
     *   description="Delete a useful link. Requires staff role",
     *   tags={"useful links"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="204", description="Deleted"),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(int $id): Response
    {
        UsefulLink::findOrFail($id)->delete();

        return response()->noContent();
    }

    /**
     * @OA\Put(
     *   path="/useful-links/order",
     *   operationId="PutUsefulLinksOrder",
     *   description="Reorder useful links. Requires staff role",
     *   tags={"useful links"},
     *   @OA\RequestBody(required=true, @OA\MediaType(
     *     mediaType="application/x-www-form-urlencoded",
     *     @OA\Schema(type="object", required={"list"}, @OA\Property(property="list", type="string", example="3,1,2", description="Comma-separated list of useful link IDs in their new order"))
     *   )),
     *   @OA\Response(response="204", description="Reordered"),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function order(Request $request): Response
    {
        $valid = Validator::make($request->all(), ['list' => ['required', 'regex:/^\d+(,\d+)*$/']])->validate();
        $ids = array_map('intval', explode(',', $valid['list']));

        DB::transaction(function () use ($ids) {
            foreach ($ids as $index => $id) {
                UsefulLink::whereKey($id)->update(['order' => $index + 1]);
            }
        });

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        $valid = Validator::make($request->all(), [
            'label' => ['required', 'string', 'between:3,35'],
            'url' => ['required', 'string', 'between:3,255'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'minRole' => ['required', 'in:'.implode(',', self::minRoles())],
        ])->validate();

        return [
            'label' => $valid['label'],
            'url' => $valid['url'],
            'title' => $valid['title'] ?? '',
            'minrole' => $valid['minRole'],
        ];
    }
}
