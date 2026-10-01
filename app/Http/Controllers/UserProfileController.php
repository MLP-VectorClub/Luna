<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Enums\UserPrefKey;
use App\Models\Appearance;
use App\Models\CutieMark;
use App\Models\Post;
use App\Models\User;
use App\Utils\ColorGuideHelper;
use App\Utils\SettingsHelper;
use App\Utils\UserPrefHelper;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;

class UserProfileController extends Controller
{
    public const CONTRIBUTION_TYPES = ['cms-provided', 'requests', 'reservations', 'finished-posts', 'fulfilled-requests'];

    /**
     * @OA\Schema(
     *   schema="UserProfile",
     *   description="Everything the profile page shows about a user, with what the current visitor may do with it",
     *   type="object",
     *   required={"user", "sameUser", "canEdit", "devOnDev", "editableRoles", "discordServerMember", "previousUsernames", "contributions", "contributionsCacheDuration", "personalGuides", "awaitingApproval"},
     *   additionalProperties=false,
     *   @OA\Property(property="user", ref="#/components/schemas/CurrentUser"),
     *   @OA\Property(property="sameUser", type="boolean", description="Whether the visitor is looking at their own profile"),
     *   @OA\Property(property="canEdit", type="boolean", description="Whether the visitor may change this user's role"),
     *   @OA\Property(property="devOnDev", type="boolean", description="Whether a developer is looking at a developer (may change the displayed role label)"),
     *   @OA\Property(property="editableRoles", type="object", nullable=true, description="Roles the visitor may assign, key to label", additionalProperties=@OA\AdditionalProperties(type="string")),
     *   @OA\Property(property="discordServerMember", type="boolean"),
     *   @OA\Property(property="previousUsernames", type="array", nullable=true, description="Only sent to the user themselves and to staff", @OA\Items(type="string")),
     *   @OA\Property(property="contributions", type="array", @OA\Items(type="object", required={"type", "count", "noun", "verb"},
     *     @OA\Property(property="type", type="string"), @OA\Property(property="count", type="integer"), @OA\Property(property="noun", type="string"), @OA\Property(property="verb", type="string")
     *   )),
     *   @OA\Property(property="contributionsCacheDuration", type="string", example="1 hour"),
     *   @OA\Property(property="personalGuides", type="array", nullable=true, description="Null when the user keeps their personal guide section private from the visitor", @OA\Items(type="object", required={"id", "label", "private", "previewData"},
     *     @OA\Property(property="id", ref="#/components/schemas/OneBasedId"), @OA\Property(property="label", type="string"), @OA\Property(property="private", type="boolean"),
     *     @OA\Property(property="previewData", type="array", @OA\Items(type="string"))
     *   )),
     *   @OA\Property(property="awaitingApproval", type="array", nullable=true, description="Finished posts waiting for approval; null when the user is not a member", @OA\Items(ref="#/components/schemas/PostItem"))
     * )
     * @OA\Get(
     *   path="/users/{id}/profile",
     *   operationId="GetUsersIdProfile",
     *   description="Everything the profile page shows about a user",
     *   tags={"users"},
     *   security={},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/UserProfile")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function profile(Request $request, int $id): JsonResponse
    {
        $user = User::with('daUser', 'discordMember')->findOrFail($id);
        $visitor = $request->user();
        $same_user = $visitor !== null && $visitor->id === $user->id;
        $is_staff = $visitor?->isStaff() ?? false;
        $can_edit = !$same_user && $is_staff && perm($user->role, $visitor->role);
        $dev_on_dev = $visitor !== null && perm(Role::Developer, $visitor->role) && perm(Role::Developer, $user->role);

        $editable_roles = null;
        if ($can_edit) {
            $editable_roles = [];
            foreach (ConfigController::ROLE_LABELS as $role => $label) {
                if ($role !== 'guest' && perm(Role::from($role), $visitor->role)) {
                    $editable_roles[$role] = $label;
                }
            }
        } elseif ($dev_on_dev) {
            $editable_roles = ConfigController::ROLE_LABELS;
        }

        $previous_names = null;
        if (($same_user || $is_staff) && $user->daUser !== null) {
            $previous_names = DB::table('previous_usernames')->where('user_id', $user->daUser->id)->orderBy('id')->pluck('username')->all();
        }

        $guides = null;
        if (!UserPrefHelper::get($user, UserPrefKey::Personal_PrivatePersonalGuide) || $same_user || $is_staff) {
            $guides = Appearance::where('owner_id', $user->id)->orderBy('order')->orderBy('id')->get()->map(fn(Appearance $a) => [
                'id' => $a->id,
                'label' => $a->label,
                'private' => (bool) $a->private,
                'previewData' => $a->private && !$same_user && !$is_staff ? [] : $a->preview_data,
            ])->all();
        }

        $contributions = Cache::remember("user_{$user->id}_contributions", 3600, fn() => $this->contributionCounts($user));

        return response()->json([
            'user' => $this->publicUser($user),
            'sameUser' => $same_user,
            'canEdit' => $can_edit,
            'devOnDev' => $dev_on_dev,
            'editableRoles' => $editable_roles,
            'discordServerMember' => $user->discordMember !== null && $user->discordMember->access !== null && $user->discordMember->joined_at !== null,
            'previousUsernames' => $previous_names,
            'contributions' => $contributions,
            'contributionsCacheDuration' => '1 hour',
            'personalGuides' => $guides,
            'awaitingApproval' => perm(Role::Member, $user->role)
                ? Post::with(['requester', 'reserver'])->where('reserved_by', $user->id)->whereNotNull('deviation_id')->where('lock', false)
                    ->orderByRaw('CASE WHEN requested_by IS NOT NULL THEN requested_at ELSE reserved_at END')->get()
                    ->map(fn(Post $post) => $post->toContract($visitor))->values()
                : null,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/users/{id}/contributions/{type}",
     *   operationId="GetUsersIdContributionsType",
     *   description="A page of what the user contributed. Their requests are only visible to themselves and staff",
     *   tags={"users"},
     *   security={},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="path", name="type", required=true, @OA\Schema(type="string", enum={"cms-provided", "requests", "reservations", "finished-posts", "fulfilled-requests"})),
     *   @OA\Parameter(in="query", name="page", @OA\Schema(type="integer", minimum=1, default=1)),
     *   @OA\Parameter(in="query", name="size", @OA\Schema(type="integer", minimum=1, maximum=50, default=10)),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"type", "items", "pagination"},
     *     @OA\Property(property="type", type="string"),
     *     @OA\Property(property="items", type="array", @OA\Items(oneOf={
     *       @OA\Schema(ref="#/components/schemas/PostItem"),
     *       @OA\Schema(type="object", required={"appearance", "favMe"}, @OA\Property(property="appearance", ref="#/components/schemas/PreviewAppearance"), @OA\Property(property="favMe", type="string", nullable=true))
     *     })),
     *     @OA\Property(property="pagination", ref="#/components/schemas/Pagination")
     *   )),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function contributions(Request $request, int $id, string $type): JsonResponse
    {
        $user = User::findOrFail($id);
        abort_unless(in_array($type, self::CONTRIBUTION_TYPES, true), 404);

        $visitor = $request->user();
        if ($type === 'requests' && ($visitor === null || ($visitor->id !== $user->id && !$visitor->isStaff()))) {
            throw $visitor === null ? new AuthenticationException() : new AuthorizationException();
        }

        $valid = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'between:1,50'],
        ], ['size.between' => 'The size must be between 1 and 50.'])->validate();
        $size = (int) ($valid['size'] ?? 10);
        $page = (int) ($valid['page'] ?? 1);

        if ($type === 'cms-provided') {
            $rows = $this->cutieMarkContributions($user);
            $total = $rows->count();
            $items = $rows->forPage($page, $size)->map(fn($row) => [
                'appearance' => ColorGuideHelper::mapPreviewAppearance(Appearance::find($row->appearance_id)),
                'favMe' => $row->favme,
            ])->values()->map(fn($item) => ['appearance' => json_decode(response()->camelJson($item['appearance'])->getContent(), true), 'favMe' => $item['favMe']]);
        } else {
            $query = $this->postContributions($user, $type);
            $total = $query->count();
            $items = $query->with(['requester', 'reserver'])->forPage($page, $size)->get()->map(fn(Post $post) => $post->toContract($visitor))->values();
        }

        return response()->json([
            'type' => $type,
            'items' => $items,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => max(1, (int) ceil($total / $size)),
                'totalItems' => $total,
                'itemsPerPage' => $size,
            ],
        ]);
    }

    private function publicUser(User $user): array
    {
        $role = $user->role === Role::Developer ? SettingsHelper::get('dev_role_label') : $user->role->value;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $role,
            'avatarUrl' => $user->avatar_url,
            'avatarProvider' => $user->avatar_provider->value,
        ];
    }

    private function postContributions(User $user, string $type)
    {
        $query = Post::query();
        switch ($type) {
            case 'requests':
                return $query->where('requested_by', $user->id)->orderByDesc('requested_at');
            case 'reservations':
                return $query->where('reserved_by', $user->id)->whereNull('requested_by')->orderByDesc('reserved_at');
            case 'finished-posts':
                return $query->where('reserved_by', $user->id)->whereNotNull('deviation_id')
                    ->orderByRaw('CASE WHEN requested_by IS NOT NULL THEN requested_at ELSE reserved_at END DESC');
            default: // fulfilled-requests
                return $query->whereNotNull('requested_by')->whereNotNull('deviation_id')->where('reserved_by', $user->id)->where('lock', true)->orderByDesc('finished_at');
        }
    }

    private function cutieMarkContributions(User $user)
    {
        if ($user->daUser === null) {
            return collect();
        }

        return CutieMark::query()
            ->join('appearances', 'appearances.id', '=', 'cutiemarks.appearance_id')
            ->where('cutiemarks.contributor_id', $user->daUser->id)
            ->whereNull('appearances.owner_id')
            ->groupBy('cutiemarks.appearance_id', 'cutiemarks.favme')
            ->orderByRaw('MIN(appearances.order) ASC')
            ->get(['cutiemarks.appearance_id', 'cutiemarks.favme']);
    }

    /**
     * @return list<array{type: string, count: int, noun: string, verb: string}>
     */
    private function contributionCounts(User $user): array
    {
        $counts = [
            'cms-provided' => [$this->cutieMarkContributions($user)->count(), 'cutie mark vector', 'provided'],
            'requests' => [$this->postContributions($user, 'requests')->count(), 'request', 'posted'],
            'reservations' => [$this->postContributions($user, 'reservations')->count(), 'reservation', 'posted'],
            'finished-posts' => [$this->postContributions($user, 'finished-posts')->count(), 'post', 'finished'],
            'fulfilled-requests' => [$this->postContributions($user, 'fulfilled-requests')->count(), 'request', 'fulfilled'],
        ];

        $result = [];
        foreach ($counts as $type => [$count, $noun, $verb]) {
            if ($count > 0) {
                $result[] = ['type' => $type, 'count' => (int) $count, 'noun' => $noun, 'verb' => $verb];
            }
        }

        return $result;
    }
}
