<?php

namespace App\Http\Controllers;

use App\Models\Appearance;
use App\Models\Log;
use App\Models\Notification;
use App\Models\Tag;
use App\Utils\AppearanceIndex;
use App\Utils\ColorGuideHelper;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
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
     * @OA\Schema(
     *   schema="LogItem",
     *   type="object",
     *   required={"id", "type", "typeLabel", "initiator", "ip", "createdAt", "hasDetails"},
     *   additionalProperties=false,
     *   @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *   @OA\Property(property="type", type="string", example="rolechange"),
     *   @OA\Property(property="typeLabel", type="string", example="User group change"),
     *   @OA\Property(property="initiator", type="object", nullable=true, description="Null when the web server itself made the change", required={"id", "name"},
     *     @OA\Property(property="id", ref="#/components/schemas/OneBasedId"), @OA\Property(property="name", type="string")
     *   ),
     *   @OA\Property(property="ip", type="string", nullable=true),
     *   @OA\Property(property="createdAt", type="string", format="date-time"),
     *   @OA\Property(property="hasDetails", type="boolean", description="Whether GET /admin/logs/{id} has anything to show")
     * )
     * @OA\Get(
     *   path="/admin/logs",
     *   operationId="GetAdminLogs",
     *   description="List log entries, newest first. Staff only",
     *   tags={"admin"},
     *   @OA\Parameter(in="query", name="type", @OA\Schema(type="string")),
     *   @OA\Parameter(in="query", name="initiatorId", description="0 selects entries made by the web server itself", @OA\Schema(type="integer", minimum=0)),
     *   @OA\Parameter(in="query", name="page", @OA\Schema(type="integer", minimum=1, default=1)),
     *   @OA\Parameter(in="query", name="size", @OA\Schema(type="integer", minimum=1, maximum=100, default=20)),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="object", required={"entries", "pagination"},
     *     @OA\Property(property="entries", type="array", @OA\Items(ref="#/components/schemas/LogItem")),
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'between:1,100'],
        ], [
            'type.in' => 'The log entry type is invalid.',
            'initiatorId.integer' => 'The initiator ID must be a non-negative integer.',
            'size.between' => 'The size must be between 1 and 100.',
        ])->validate();
        $size = (int) ($valid['size'] ?? 20);

        $pagination = Log::with('actor:id,name')
            ->when(isset($valid['type']), fn($query) => $query->where('entry_type', $valid['type']))
            ->when(isset($valid['initiatorId']), fn($query) => (int) $valid['initiatorId'] === 0 ? $query->whereNull('initiator') : $query->where('initiator', (int) $valid['initiatorId']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($size, page: (int) ($valid['page'] ?? 1));

        return response()->json([
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
        }

        return response()->noContent();
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
     *   description="Download the full color guide as a JSON file (same layout as Winterchilla's `mlpvc-colorguide.json`). Developer permission required",
     *   tags={"color guide"},
     *   @OA\Response(response="200", description="The export file", @OA\MediaType(mediaType="application/json", @OA\Schema(type="object"))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function export()
    {
        $data = ['Appearances' => [], 'Tags' => []];
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

        return response()->json($data, 200, ['Content-Disposition' => 'attachment; filename="mlpvc-colorguide.json"']);
    }
}
