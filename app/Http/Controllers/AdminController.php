<?php

namespace App\Http\Controllers;

use App\Models\Appearance;
use App\Models\CutieMark;
use App\Models\Log;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Utils\AppearanceIndex;
use App\Utils\ColorGuideHelper;
use App\Utils\WebsocketServer;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Elasticsearch;
use OpenApi\Annotations as OA;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminController extends Controller
{
    /** Audit log entry types shared with Winterchilla and their labels */
    public const LOG_TYPES = [
        'rolechange' => 'User group change',
        'userfetch' => 'Fetch user details',
        'req_delete' => 'Request deleted',
        'img_update' => 'Post image updated',
        'res_overtake' => 'Overtook post reservation',
        'appearances' => 'Appearance management',
        'res_transfer' => 'Reservation transferred',
        'cg_modify' => 'Color group modified',
        'cgs' => 'Color group management',
        'cg_order' => 'Color groups re-ordered',
        'appearance_modify' => 'Appearance modified',
        'video_broken' => 'Broken video removed',
        'cm_modify' => 'Appearance CM edited',
        'cm_delete' => 'Appearance CM deleted',
        'post_fix' => 'Broken post restored',
        'staff_limits' => 'Account limitation changed',
        'derpimerge' => 'Derpibooru merge detected',
    ];

    /**
     * @OA\Get(
     *   path="/admin/pcg-appearances",
     *   operationId="GetAdminPcgAppearances",
     *   description="List every personal color guide appearance of every user, newest first. Staff only.",
     *   tags={"admin"},
     *   @OA\Parameter(in="query", name="page", required=false, @OA\Schema(type="integer", default=1, minimum=1)),
     *   @OA\Parameter(in="query", name="size", required=false, @OA\Schema(type="integer", default=10, minimum=1, maximum=100)),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"appearances", "pagination"},
     *     @OA\Property(property="appearances", type="array", @OA\Items(allOf={
     *       @OA\Schema(ref="#/components/schemas/PreviewAppearance"),
     *       @OA\Schema(type="object", required={"private", "createdAt", "owner", "sprite", "cutieMarks"}, @OA\Property(property="private", type="boolean"), @OA\Property(property="createdAt", type="string", format="date-time"),
     *         @OA\Property(property="owner", type="object", nullable=true, required={"id", "name"}, @OA\Property(property="id", type="integer"), @OA\Property(property="name", type="string")),
     *         @OA\Property(property="sprite", nullable=true, allOf={@OA\Schema(ref="#/components/schemas/Sprite")}),
     *         @OA\Property(property="cutieMarks", type="array", @OA\Items(ref="#/components/schemas/CutieMark")))
     *     })),
     *     @OA\Property(property="pagination", ref="#/components/schemas/Pagination")
     *   )),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Invalid query", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function pcgAppearances(Request $request): JsonResponse
    {
        $valid = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'between:1,100'],
        ], ['size.between' => 'The size must be between 1 and 100.'])->validate();
        $size = (int) ($valid['size'] ?? 10);

        $pagination = Appearance::with('owner')->whereNotNull('owner_id')->orderByDesc('created_at')->orderByDesc('id')->paginate($size, page: (int) ($valid['page'] ?? 1));

        return response()->camelJson([
            'appearances' => $pagination->getCollection()->map(fn(Appearance $a) => ColorGuideHelper::mapPreviewAppearance($a) + [
                'private' => (bool) $a->private,
                'created_at' => $a->created_at?->toIso8601String(),
                'owner' => $a->owner ? ['id' => $a->owner->id, 'name' => $a->owner->name] : null,
                'sprite' => ColorGuideHelper::mapSprite($a),
                'cutie_marks' => $a->cutiemarks()->get()
                    ->filter(fn (CutieMark $cutiemark) => $cutiemark->vectorFile() !== null)
                    ->map(fn (CutieMark $cutiemark) => ColorGuideHelper::mapCutieMark($cutiemark))->values()->toArray(),
            ])->values(),
            'pagination' => [
                'current_page' => $pagination->currentPage(),
                'total_pages' => max(1, $pagination->lastPage()),
                'total_items' => $pagination->total(),
                'items_per_page' => $size,
            ],
        ]);
    }

    /**
     * @OA\Get(
     *   path="/admin/logs",
     *   operationId="GetAdminLogs",
     *   description="List log entries, newest first. Staff only",
     *   tags={"admin"},
     *   @OA\Parameter(in="query", name="type", @OA\Schema(type="string")),
     *   @OA\Parameter(in="query", name="initiatorId", description="0 selects entries made by the web server itself", @OA\Schema(type="integer", minimum=0)),
     *   @OA\Parameter(in="query", name="by", description="Like the old site's filter box: a user name, `me`, `Web server`, `my IP` or an IP address (not part of Winterchilla's contract)", @OA\Schema(type="string", maxLength=45)),
     *   @OA\Parameter(in="query", name="page", @OA\Schema(type="integer", minimum=1, default=1)),
     *   @OA\Parameter(in="query", name="size", @OA\Schema(type="integer", minimum=1, maximum=100, default=20)),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"entries", "pagination", "entryTypes"},
     *     @OA\Property(property="entries", type="array", @OA\Items(ref="#/components/schemas/LogItem")),
     *     @OA\Property(property="entryTypes", type="object", description="Every entry type with its label, for the filter", additionalProperties=@OA\AdditionalProperties(type="string")),
     *     @OA\Property(property="pagination", ref="#/components/schemas/Pagination")
     *   )),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function logs(Request $request): JsonResponse
    {
        $valid = Validator::make($request->query(), [
            'type' => ['sometimes', 'string', 'in:'.implode(',', array_keys(self::LOG_TYPES))],
            'initiatorId' => ['sometimes', 'integer', 'min:0'],
            'by' => ['sometimes', 'string', 'max:45'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'between:1,100'],
        ], [
            'type.in' => 'The log entry type is invalid.',
            'initiatorId.integer' => 'The initiator ID must be a non-negative integer.',
            'size.between' => 'The size must be between 1 and 100.',
        ])->validate();
        $size = (int) ($valid['size'] ?? 20);

        // The filter box of the old site: a name, `me`, `Web server`, `my IP` or an address
        $initiator = $valid['initiatorId'] ?? null;
        $ip = null;
        if (isset($valid['by']) && trim($valid['by']) !== '') {
            $by = strtolower(trim($valid['by']));
            if (in_array($by, ['me', 'you'], true)) {
                $initiator = $request->user()->id;
            } elseif (in_array($by, ['my ip', 'your ip'], true)) {
                $ip = $request->ip();
            } elseif ($by === 'web server') {
                $initiator = 0;
            } elseif (preg_match('/^[\da-f.:]+$/', $by) && (str_contains($by, '.') || str_contains($by, ':'))) {
                $ip = $by;
            } else {
                $initiator = User::whereRaw('lower(name) = ?', [$by])->value('id') ?? -1;
            }
        }

        $pagination = Log::with('actor:id,name')
            ->when(isset($valid['type']), fn($query) => $query->where('entry_type', $valid['type']))
            ->when($initiator !== null, fn($query) => (int) $initiator === 0 ? $query->whereNull('initiator') : $query->where('initiator', (int) $initiator))
            ->when($ip !== null, fn($query) => $query->where('ip', $ip))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($size, page: (int) ($valid['page'] ?? 1));

        return response()->json([
            'entryTypes' => self::LOG_TYPES,
            'entries' => $pagination->getCollection()->map(fn(Log $log) => [
                'id' => $log->id,
                'type' => $log->entry_type,
                'typeLabel' => self::LOG_TYPES[$log->entry_type] ?? $log->entry_type,
                'initiator' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name] : null,
                'ip' => $log->ip,
                'createdAt' => $log->created_at->toIso8601String(),
                'hasDetails' => $log->data !== null,
            ])->values(),
            'pagination' => [
                'currentPage' => $pagination->currentPage(),
                'totalPages' => max(1, $pagination->lastPage()),
                'totalItems' => $pagination->total(),
                'itemsPerPage' => $size,
            ],
        ]);
    }

    /**
     * @OA\Get(
     *   path="/admin/logs/{id}",
     *   operationId="GetAdminLogsId",
     *   description="Get the data logged with an entry. Staff only",
     *   tags={"admin"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"details", "data"},
     *     @OA\Property(property="details", type="array", description="Always empty in Luna, use `data`", @OA\Items(type="array", @OA\Items())),
     *     @OA\Property(property="data", type="object", additionalProperties=true, description="The structured data that was logged with the entry, its keys depend on the entry type")
     *   )),
     *   @OA\Response(response="404", description="Not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="There are no details to show", @OA\JsonContent(type="object", required={"message", "unclickable"}, @OA\Property(property="message", type="string"), @OA\Property(property="unclickable", type="boolean")))
     * )
     */
    public function logDetail(int $id): JsonResponse
    {
        $log = Log::findOrFail($id);
        if ($log->data === null) {
            return response()->json(['message' => 'There are no details to show', 'unclickable' => true], 409);
        }

        return response()->json(['details' => [], 'data' => $log->data]);
    }

    /**
     * @OA\Get(
     *   path="/notifications",
     *   operationId="GetNotifications",
     *   description="The signed in user's unread notifications, oldest first. A post notification carries the post and its show (null when the post is gone)",
     *   tags={"notifications"},
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"notifications"}, @OA\Property(property="notifications", type="array",
     *     @OA\Items(type="object", required={"id", "type", "createdAt", "post"},
     *       @OA\Property(property="id", type="integer"),
     *       @OA\Property(property="type", type="string", enum={"post-finished", "post-approved"}),
     *       @OA\Property(property="createdAt", type="string", format="date-time"),
     *       @OA\Property(property="post", type="object", nullable=true, required={"id", "show"}, @OA\Property(property="id", type="integer"), @OA\Property(property="show", ref="#/components/schemas/ShowListItem"))
     *     )))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function notifications(Request $request): JsonResponse
    {
        $list = Notification::where('recipient_id', $request->user()->id)->whereNull('read_at')->orderBy('id')->get();
        $posts = Post::with('show')->whereIn('id', $list->map(fn (Notification $n) => $n->data['id'] ?? 0)->all())->get()->keyBy('id');

        return response()->json(['notifications' => $list->map(function (Notification $n) use ($posts) {
            $post = $posts->get($n->data['id'] ?? 0);

            return [
                'id' => $n->id,
                'type' => $n->type,
                'createdAt' => $n->created_at?->toIso8601String(),
                'post' => $post === null ? null : ['id' => $post->id, 'show' => ShowController::mapShowListItem($post->show)],
            ];
        })->values()]);
    }

    /**
     * @OA\Post(
     *   path="/notifications/{id}/read",
     *   operationId="PostNotificationsIdRead",
     *   description="Mark one of the current user's notifications as read",
     *   tags={"notifications"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="204", description="Marked as read"),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Not found, or it belongs to somebody else", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function readNotification(Request $request, int $id): Response
    {
        $notification = Notification::where('recipient_id', $request->user()->id)->findOrFail($id);
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
            // The user's other open tabs
            WebsocketServer::notifyUser($notification->recipient_id);
        }

        return response()->noContent();
    }

    /**
     * @OA\Get(
     *   path="/admin/posts/recent",
     *   operationId="GetAdminPostsRecent",
     *   description="The 20 most recently posted requests and reservations, for the admin area. Requires staff",
     *   tags={"admin"},
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"posts"}, @OA\Property(property="posts", type="array",
     *     @OA\Items(allOf={@OA\Schema(ref="#/components/schemas/PostItem"), @OA\Schema(type="object", required={"show"}, @OA\Property(property="show", ref="#/components/schemas/ShowListItem"))})))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function recentPosts(Request $request): JsonResponse
    {
        $posts = Post::with(['requester', 'reserver', 'show'])
            ->orderByRaw('CASE WHEN requested_by IS NOT NULL THEN requested_at ELSE reserved_at END DESC')->orderByDesc('id')->limit(20)->get()
            ->map(fn(Post $post) => $post->toContract($request->user()) + ['show' => ShowController::mapShowListItem($post->show)])->values();

        return response()->json(['posts' => $posts]);
    }

    /**
     * @OA\Get(
     *   path="/admin/search-status",
     *   operationId="GetAdminSearchStatus",
     *   description="State of the ElasticSearch server behind the color guide search. Requires developer permission",
     *   tags={"admin"},
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"down", "indices", "nodes"},
     *     @OA\Property(property="down", type="boolean"), @OA\Property(property="indices", type="array", @OA\Items(type="string")), @OA\Property(property="nodes", type="array", @OA\Items(type="string")))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function searchStatus(): JsonResponse
    {
        try {
            $client = Elasticsearch::connection();
            if (!$client->ping()->asBool()) {
                return response()->json(['down' => true, 'indices' => [], 'nodes' => []]);
            }
            $describe = fn(array $rows) => array_map(function (array $row, int $no) {
                $line = "#$no ";
                foreach ($row as $key => $value) {
                    if ($value !== null && $value !== '') {
                        $line .= "$key:$value ";
                    }
                }

                return trim($line);
            }, $rows, array_keys($rows));
            $indices = array_values(array_filter($client->cat()->indices(['format' => 'json'])->asArray(), fn(array $index) => ($index['index'] ?? null) === 'appearances'));

            return response()->json(['down' => false, 'indices' => $describe($indices), 'nodes' => $describe($client->cat()->nodes(['format' => 'json'])->asArray())]);
        } catch (\Throwable $e) {
            return response()->json(['down' => true, 'indices' => [], 'nodes' => []]);
        }
    }

    /**
     * @OA\Post(
     *   path="/color-guide/reindex",
     *   operationId="PostColorGuideReindex",
     *   description="Rebuild the color guide search index. Developer permission required",
     *   tags={"color guide"},
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"message"}, @OA\Property(property="message", type="string"))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="503", description="ElasticSearch is down", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function reindex(): JsonResponse
    {
        try {
            $count = AppearanceIndex::rebuild();
        } catch (NoNodeAvailableException $e) {
            throw new HttpException(503, 'Re-index failed, ElasticSearch server is down!');
        }

        return response()->json(['message' => "Re-index successful, $count ".($count === 1 ? 'appearance was' : 'appearances were').' indexed']);
    }

    /**
     * @OA\Get(
     *   path="/color-guide/export",
     *   operationId="GetColorGuideExport",
     *   description="The full color guide as a JSON file (same layout as Winterchilla's public `mlpvc-colorguide.json`, which tools such as the swatch import script for Adobe Illustrator read; colors of private appearances are left out). Public, kept for an hour",
     *   tags={"color guide"},
     *   security={},
     *   @OA\Response(response="200", description="The export file", @OA\MediaType(mediaType="application/json", @OA\Schema(type="object")))
     * )
     */
    public function export()
    {
        $data = ['$schema' => rtrim((string) config('app.frontend_url'), '/').'/dist/mlpvc-colorguide-schema.json?v1.1', 'Appearances' => [], 'Tags' => []];
        foreach (Tag::orderBy('id')->get() as $tag) {
            $data['Tags'][$tag->id] = $tag->only(['id', 'name', 'title', 'type', 'uses', 'synonym_of']);
        }

        $appearances = Appearance::whereNull('owner_id')->with(['colorGroups.colors', 'cutiemarks', 'tags', 'relatedAppearances'])
            ->orderByRaw('CASE WHEN "order" IS NULL THEN 1 ELSE 0 END')->orderBy('order')->orderBy('id')->get();
        foreach ($appearances as $appearance) {
            $entry = [
                'id' => $appearance->id,
                'order' => $appearance->order,
                'label' => $appearance->label,
                'notes' => $appearance->notes_src ?? '',
                'guide' => $appearance->guide?->value,
                'added' => $appearance->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'private' => (bool) $appearance->private,
            ];
            $sprite = ColorGuideHelper::mapSprite($appearance);
            if ($sprite !== null) {
                $entry['Sprite'] = $sprite['path'];
            }

            $cutie_marks = [];
            foreach ($appearance->cutiemarks as $cutiemark) {
                $item = ['facing' => $cutiemark->facing, 'svg' => $cutiemark->vectorFile()?->getFullUrl()];
                if ($cutiemark->favme !== null) {
                    $item['source'] = "http://fav.me/{$cutiemark->favme}";
                }
                $cutie_marks[$cutiemark->id] = $item;
            }
            if ($cutie_marks !== []) {
                $entry['CutieMark'] = $cutie_marks;
            }

            $entry['ColorGroups'] = [];
            if (!$entry['private']) {
                foreach ($appearance->colorGroups as $group) {
                    $entry['ColorGroups'][$group->id] = [
                        'id' => $group->id,
                        'label' => $group->label,
                        'order' => $group->order,
                        'Colors' => $group->colors->sortBy('order')->map(fn($color) => ['order' => $color->order, 'label' => $color->label, 'hex' => $color->hex])->values()->all(),
                    ];
                }
            } else {
                $entry['ColorGroups']['_hidden'] = true;
            }
            $entry['TagIDs'] = $appearance->tags->pluck('id')->all();
            $entry['RelatedAppearances'] = $appearance->relatedAppearances->pluck('id')->all();

            $data['Appearances'][$appearance->id] = $entry;
        }

        return response()->json($data, 200, ['Last-Modified' => now()->toRfc7231String()]);
    }
}
