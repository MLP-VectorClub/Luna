<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Enums\UserPrefKey;
use App\Models\Appearance;
use App\Models\CutieMark;
use App\Models\DiscordMember;
use App\Models\Post;
use App\Models\User;
use App\Utils\ColorGuideHelper;
use App\Utils\SettingsHelper;
use App\Utils\UserPrefHelper;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;

class UserProfileController extends Controller
{
    public const CONTRIBUTION_TYPES = ['cms-provided', 'requests', 'reservations', 'finished-posts', 'fulfilled-requests'];

    /**
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

        $is_member = perm(Role::Member, $user->role);
        // What the visitor may see of the user's reservations: their own, or those of a member when staff visits
        $pending = null;
        if ($visitor !== null && ($same_user || ($is_staff && $is_member))) {
            $pending = Post::with(['requester', 'reserver', 'show'])->where('reserved_by', $user->id)->whereNull('deviation_id')
                ->orderBy('reserved_at')->orderBy('id')->get()
                ->map(fn(Post $post) => $this->withShow($post, $visitor))->values();
        }

        $progress = null;
        if ($same_user || $is_staff) {
            $points = $user->pcgAvailablePoints();
            $progress = ['slots' => intdiv($points, 10), 'requestsToNext' => 10 - ($points % 10)];
        }

        // The Discord section of the account page, for the user themselves and staff
        $discord = null;
        if (($same_user || $is_staff) && $user->discordMember !== null) {
            $member = $user->discordMember;
            $discord = [
                'linked' => $member->access !== null,
                'tag' => $member->username.($member->discriminator > 0 ? '#'.str_pad((string) $member->discriminator, 4, '0', STR_PAD_LEFT) : ''),
                'serverMember' => $member->joined_at !== null,
                'lastSynced' => $member->last_synced?->toISOString(),
                'syncCooldown' => DiscordMember::SYNC_COOLDOWN,
                'canSync' => $member->last_synced === null || $member->last_synced->getTimestamp() + DiscordMember::SYNC_COOLDOWN <= time(),
            ];
        }

        $da_user = $user->daUser;
        $vector_app = UserPrefHelper::get($user, UserPrefKey::Personal_VectorApp);

        return response()->json([
            'user' => $this->publicUser($user),
            'deviantArtUrl' => $da_user === null ? null : 'https://www.deviantart.com/'.$da_user->name,
            'vectorApp' => $vector_app instanceof \BackedEnum ? $vector_app->value : $vector_app,
            'discordName' => $user->discordMember?->display_name,
            'discord' => $discord,
            'developerInfo' => $visitor !== null && perm(Role::Developer, $visitor->role)
                ? ['deviantArtId' => $da_user?->id, 'discordId' => $user->discordMember?->id]
                : null,
            'personalGuideProgress' => $progress,
            'pendingReservations' => $pending,
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
                ? Post::with(['requester', 'reserver', 'show'])->where('reserved_by', $user->id)->whereNotNull('deviation_id')->where('lock', false)
                    ->orderByRaw('CASE WHEN requested_by IS NOT NULL THEN requested_at ELSE reserved_at END')->get()
                    ->map(fn(Post $post) => $this->withShow($post, $visitor))->values()
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

    /**
     * @OA\Delete(
     *   path="/users/{id}/contributions/cache",
     *   operationId="DeleteUsersIdContributionsCache",
     *   description="Forget the cached contribution counts of the profile so they are counted again. Requires staff",
     *   tags={"users"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="204", description="Purged"),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function purgeContributionsCache(int $id): Response
    {
        $user = User::findOrFail($id);
        Cache::forget("user_{$user->id}_contributions");

        return response()->noContent();
    }

    /** A post as the contract describes it, plus the show it belongs to ("Posted under S01 E01: …" on the profile) */
    private function withShow(Post $post, ?User $visitor): array
    {
        return $post->toContract($visitor) + ['show' => ShowController::mapShowListItem($post->show)];
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

        // Not a list of its own: posts of this user that were accepted into the group gallery
        $counts['approved-posts'] = [DB::table('locked_posts')->where('user_id', $user->id)->count(), 'post', 'marked approved'];

        $result = [];
        foreach ($counts as $type => [$count, $noun, $verb]) {
            if ($count > 0) {
                $result[] = ['type' => $type, 'count' => (int) $count, 'noun' => $noun, 'verb' => $verb];
            }
        }

        return $result;
    }
}
