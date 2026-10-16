<?php

namespace App\Http\Controllers;

use App\Models\Notice;
use App\Utils\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class NoticesController extends Controller
{
    #[OA\Schema(
        schema: 'Notice',
        description: 'A site-wide notice shown until it is hidden',
        type: 'object',
        required: ['id', 'type', 'messageHtml', 'showAfter', 'hideAfter', 'site', 'postedBy', 'createdAt'],
        additionalProperties: false,
        properties: [
            new OA\Property(property: 'id', ref: '#/components/schemas/OneBasedId'),
            new OA\Property(property: 'type', type: 'string', enum: ['info', 'success', 'fail', 'warn', 'caution']),
            new OA\Property(
                property: 'messageHtml',
                type: 'string',
                description: 'Sanitized HTML of the message, `{path}` stands for the address of the page being viewed (without the host)',
            ),
            new OA\Property(
                property: 'showAfter',
                type: 'string',
                format: 'date-time',
                nullable: true,
                description: 'The notice is not shown before this time, null shows it at once',
            ),
            new OA\Property(property: 'hideAfter', type: 'string', format: 'date-time'),
            new OA\Property(
                property: 'site',
                type: 'string',
                enum: ['all', 'celestia', 'winterchilla'],
                description: 'The site that shows the notice',
            ),
            new OA\Property(property: 'postedBy', type: 'integer', nullable: true),
            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        ],
    )]
    #[OA\Schema(
        schema: 'Pagination',
        type: 'object',
        required: ['currentPage', 'totalPages', 'totalItems', 'itemsPerPage'],
        additionalProperties: false,
        properties: [
            new OA\Property(property: 'currentPage', type: 'integer', minimum: 1),
            new OA\Property(property: 'totalPages', type: 'integer', minimum: 1),
            new OA\Property(property: 'totalItems', type: 'integer', minimum: 0),
            new OA\Property(property: 'itemsPerPage', type: 'integer', minimum: 1),
        ],
    )]
    #[OA\Schema(
        schema: 'NoticeInput',
        type: 'object',
        required: ['messageHtml', 'hideAfter', 'type'],
        properties: [
            new OA\Property(
                property: 'messageHtml',
                type: 'string',
                maxLength: 500,
                description: 'Printable ASCII only; unsafe HTML is stripped, links are kept. `{path}` is replaced with the address of the page being viewed',
            ),
            new OA\Property(
                property: 'showAfter',
                type: 'string',
                format: 'date-time',
                nullable: true,
                description: 'Not shown before this time (null: at once), must be before hideAfter',
            ),
            new OA\Property(
                property: 'hideAfter',
                type: 'string',
                format: 'date-time',
                description: 'Must be in the future',
            ),
            new OA\Property(
                property: 'site',
                type: 'string',
                enum: ['all', 'celestia', 'winterchilla'],
                default: 'all',
            ),
            new OA\Property(property: 'type', type: 'string', enum: ['info', 'success', 'fail', 'warn', 'caution']),
        ],
    )]
    #[OA\Get(
        path: '/notices/current',
        operationId: 'GetNoticesCurrent',
        description: 'Notices this site shows right now (started, not hidden yet), newest first',
        tags: ['notices'],
        security: [],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Notice')),
            ),
        ],
    )]
    public function current(): JsonResponse
    {
        return response()->json(
            Notice::visible()->orderByDesc('created_at')->orderByDesc('id')->get()
                ->map(fn(Notice $notice) => $notice->toContract())
        );
    }

    #[OA\Get(
        path: '/notices',
        operationId: 'GetNotices',
        description: 'List every notice, newest first. Staff only.',
        tags: ['notices'],
        parameters: [
            new OA\Parameter(in: 'query', name: 'page', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(
                in: 'query',
                name: 'size',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['notices', 'pagination'],
                    additionalProperties: false,
                    properties: [
                        new OA\Property(
                            property: 'notices',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Notice'),
                        ),
                        new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
                    ],
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not signed in',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '403',
                description: 'Insufficient permissions',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
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

    #[OA\Get(
        path: '/notices/{id}',
        operationId: 'GetNoticesId',
        description: 'Get a notice. Staff only.',
        tags: ['notices'],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/OneBasedId'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/Notice'),
            ),
            new OA\Response(
                response: '401',
                description: 'Not signed in',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '403',
                description: 'Insufficient permissions',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '404',
                description: 'Notice not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        return response()->json(Notice::findOrFail($id)->toContract());
    }

    #[OA\Post(
        path: '/notices',
        operationId: 'PostNotices',
        description: 'Create a notice. Staff only.',
        tags: ['notices'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/NoticeInput'),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/Notice'),
            ),
            new OA\Response(
                response: '401',
                description: 'Not signed in',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '403',
                description: 'Insufficient permissions',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function create(Request $request): JsonResponse
    {
        $notice = new Notice(['posted_by' => $request->user()->id]);
        $this->fill($notice, $request);

        return response()->json($notice->fresh()->toContract(), 201);
    }

    #[OA\Put(
        path: '/notices/{id}',
        operationId: 'PutNoticesId',
        description: 'Replace a notice\'s message, end time and type. Staff only.',
        tags: ['notices'],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/OneBasedId'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/NoticeInput'),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Updated',
                content: new OA\JsonContent(ref: '#/components/schemas/Notice'),
            ),
            new OA\Response(
                response: '401',
                description: 'Not signed in',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '403',
                description: 'Insufficient permissions',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '404',
                description: 'Notice not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $notice = Notice::findOrFail($id);
        $this->fill($notice, $request);

        return response()->json($notice->fresh()->toContract());
    }

    #[OA\Delete(
        path: '/notices/{id}',
        operationId: 'DeleteNoticesId',
        description: 'Delete a notice. Staff only.',
        tags: ['notices'],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'id',
                required: true,
                schema: new OA\Schema(ref: '#/components/schemas/OneBasedId'),
            ),
        ],
        responses: [
            new OA\Response(response: '204', description: 'Deleted'),
            new OA\Response(
                response: '401',
                description: 'Not signed in',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '403',
                description: 'Insufficient permissions',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '404',
                description: 'Notice not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
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
