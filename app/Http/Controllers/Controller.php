<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use OpenApi\Attributes as OA;

#[OA\OpenApi(
    info: new OA\Info(
        title: 'MLP Vector Club API',
        version: \API_VERSION,
        description: 'A work-in-progress API for the [MLP Vector Club](https://mlpvector.club/)\'s website.',
        license: new OA\License(name: 'MIT'),
        contact: new OA\Contact(name: 'WentTheFox', url: 'https://went.tf'),
    ),
    servers: [new OA\Server(url: '/', description: 'Current Host')],
    tags: [
        new OA\Tag(
            name: 'authentication',
            description: 'Endpoints related to getting a user logged in or out, as well as checking logged in status',
        ),
        new OA\Tag(name: 'appearances', description: 'Working with entries in the color guide'),
        new OA\Tag(name: 'server info', description: 'For diagnostic or informational data'),
    ],
)]
#[OA\Schema(
    schema: 'IsoStandardDate',
    type: 'string',
    format: 'date-time',
    description: 'An ISO 8601 standard compliant date as a string',
)]
#[OA\Get(
    path: '/sanctum/csrf-cookie',
    description: 'Initialize CSRF protection by sending a dummy request through the web middleware. Used only for session-based authentication.',
    tags: ['authentication'],
    security: [],
    responses: [new OA\Response(response: '204', description: 'Success')],
)]
#[OA\Schema(
    schema: 'PageNumber',
    type: 'integer',
    minimum: 1,
    default: 1,
    description: 'A query parameter used for specifying which page is currently being displayed',
)]
#[OA\Schema(schema: 'SVGFile', type: 'string', format: 'svg')]
#[OA\Schema(schema: 'QueryString', type: 'string', default: '')]
#[OA\Schema(schema: 'OneBasedId', type: 'integer', minimum: 1, example: 1)]
#[OA\Schema(schema: 'ZeroBasedId', type: 'integer', minimum: 0, example: 1)]
class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
}
