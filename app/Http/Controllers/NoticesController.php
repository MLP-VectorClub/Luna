<?php

namespace App\Http\Controllers;

use App\Models\Notice;
use App\Utils\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;

class NoticesController extends Controller
{
    /**
     * @OA\Schema(
     *   schema="Notice",
     *   description="A site-wide notice shown until it is hidden",
     *   type="object",
     *   required={"id", "type", "messageHtml", "showAfter", "hideAfter", "site", "postedBy", "createdAt"},
     *   additionalProperties=false,
     *   @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *   @OA\Property(property="type", type="string", enum={"info", "success", "fail", "warn", "caution"}),
     *   @OA\Property(property="messageHtml", type="string", description="Sanitized HTML of the message, `{path}` stands for the address of the page being viewed (without the host)"),
     *   @OA\Property(property="showAfter", type="string", format="date-time", nullable=true, description="The notice is not shown before this time, null shows it at once"),
     *   @OA\Property(property="hideAfter", type="string", format="date-time"),
     *   @OA\Property(property="site", type="string", enum={"all", "celestia", "winterchilla"}, description="The site that shows the notice"),
     *   @OA\Property(property="postedBy", type="integer", nullable=true),
     *   @OA\Property(property="createdAt", type="string", format="date-time")
     * )
     * @OA\Schema(
     *   schema="Pagination",
     *   type="object",
     *   required={"currentPage", "totalPages", "totalItems", "itemsPerPage"},
     *   additionalProperties=false,
     *   @OA\Property(property="currentPage", type="integer", minimum=1),
     *   @OA\Property(property="totalPages", type="integer", minimum=1),
     *   @OA\Property(property="totalItems", type="integer", minimum=0),
     *   @OA\Property(property="itemsPerPage", type="integer", minimum=1)
     * )
     * @OA\Schema(
     *   schema="NoticeInput",
     *   type="object",
     *   required={"messageHtml", "hideAfter", "type"},
     *   @OA\Property(property="messageHtml", type="string", maxLength=500, description="Printable ASCII only; unsafe HTML is stripped, links are kept. `{path}` is replaced with the address of the page being viewed"),
     *   @OA\Property(property="showAfter", type="string", format="date-time", nullable=true, description="Not shown before this time (null: at once), must be before hideAfter"),
     *   @OA\Property(property="hideAfter", type="string", format="date-time", description="Must be in the future"),
     *   @OA\Property(property="site", type="string", enum={"all", "celestia", "winterchilla"}, default="all"),
     *   @OA\Property(property="type", type="string", enum={"info", "success", "fail", "warn", "caution"})
     * )
     * @OA\Get(
     *   path="/notices/current",
     *   operationId="GetNoticesCurrent",
     *   description="Notices this site shows right now (started, not hidden yet), newest first",
     *   tags={"notices"},
     *   security={},
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Notice")))
     * )
     */
    public function current(): JsonResponse
    {
        return response()->json(
            Notice::visible()->orderByDesc('created_at')->orderByDesc('id')->get()
                ->map(fn(Notice $notice) => $notice->toContract())
        );
    }

    /**
     * @OA\Get(
     *   path="/notices",
     *   operationId="GetNotices",
     *   description="List every notice, newest first. Staff only.",
     *   tags={"notices"},
     *   @OA\Parameter(in="query", name="page", @OA\Schema(type="integer", minimum=1, default=1)),
     *   @OA\Parameter(in="query", name="size", @OA\Schema(type="integer", minimum=1, maximum=100, default=25)),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(
     *     type="object", required={"notices", "pagination"}, additionalProperties=false,
     *     @OA\Property(property="notices", type="array", @OA\Items(ref="#/components/schemas/Notice")),
     *     @OA\Property(property="pagination", ref="#/components/schemas/Pagination")
     *   )),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $valid = Validator::make($request->query(), [
            'page' => 'sometimes|integer|min:1',
            'size' => 'sometimes|integer|between:1,100',
        ])->validate();

        $size = (int) ($valid['size'] ?? 25);
        $pagination = Notice::orderByDesc('created_at')->orderByDesc('id')->paginate($size, page: (int) ($valid['page'] ?? 1));

        return response()->json([
            'notices' => $pagination->getCollection()->map(fn(Notice $notice) => $notice->toContract())->values(),
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
     *   path="/notices/{id}",
     *   operationId="GetNoticesId",
     *   description="Get a notice. Staff only.",
     *   tags={"notices"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/Notice")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Notice not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(int $id): JsonResponse
    {
        return response()->json(Notice::findOrFail($id)->toContract());
    }

    /**
     * @OA\Post(
     *   path="/notices",
     *   operationId="PostNotices",
     *   description="Create a notice. Staff only.",
     *   tags={"notices"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/NoticeInput")),
     *   @OA\Response(response="201", description="Created", @OA\JsonContent(ref="#/components/schemas/Notice")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function create(Request $request): JsonResponse
    {
        $notice = new Notice(['posted_by' => $request->user()->id]);
        $this->fill($notice, $request);

        return response()->json($notice->fresh()->toContract(), 201);
    }

    /**
     * @OA\Put(
     *   path="/notices/{id}",
     *   operationId="PutNoticesId",
     *   description="Replace a notice's message, end time and type. Staff only.",
     *   tags={"notices"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/NoticeInput")),
     *   @OA\Response(response="200", description="Updated", @OA\JsonContent(ref="#/components/schemas/Notice")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Notice not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $notice = Notice::findOrFail($id);
        $this->fill($notice, $request);

        return response()->json($notice->fresh()->toContract());
    }

    /**
     * @OA\Delete(
     *   path="/notices/{id}",
     *   operationId="DeleteNoticesId",
     *   description="Delete a notice. Staff only.",
     *   tags={"notices"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Response(response="204", description="Deleted"),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Notice not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(int $id): Response
    {
        Notice::findOrFail($id)->delete();

        return response()->noContent();
    }

    private function fill(Notice $notice, Request $request): void
    {
        $valid = Validator::make($request->all(), [
            'messageHtml' => ['required', 'string', 'max:500', 'regex:/^[ -~\n]+$/'],
            'showAfter' => ['nullable', 'date', 'before:hideAfter'],
            'hideAfter' => ['required', 'date', 'after:now'],
            'site' => ['sometimes', 'in:'.implode(',', Notice::SITES)],
            'type' => ['required', 'in:'.implode(',', Notice::TYPES)],
        ])->validate();

        // Links stay. The purifier drops an address with braces in it, so the placeholder is swapped for something it accepts while it works
        $token = 'zzpathplaceholderzz';
        $message = str_replace('{path}', $token, $valid['messageHtml']);
        $notice->message_html = str_replace($token, '{path}', HtmlSanitizer::sanitize($message, ['a'], ['a.href']));
        $notice->show_after = $valid['showAfter'] ?? null;
        $notice->hide_after = $valid['hideAfter'];
        $notice->site = $valid['site'] ?? 'all';
        $notice->type = $valid['type'];
        $notice->save();
    }
}
