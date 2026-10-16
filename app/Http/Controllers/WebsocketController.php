<?php

namespace App\Http\Controllers;

use App\Utils\WebsocketServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class WebsocketController extends Controller
{
    #[OA\Post(
        path: '/users/me/socket-token',
        operationId: 'PostUsersMeSocketToken',
        description: 'A one time token that lets the signed in user\'s browser identify itself to the websocket server (which pushes notifications). It works for one connection and a couple of minutes, ask for a new one for every (re)connection. 404 when there is no websocket server',
        tags: ['users'],
        responses: [
            new OA\Response(
                response: '200',
                description: 'OK',
                content: new OA\JsonContent(
                    type: 'object',
                    additionalProperties: false,
                    required: ['token', 'expiresIn'],
                    properties: [
                        new OA\Property(property: 'token', type: 'string'),
                        new OA\Property(property: 'expiresIn', type: 'integer', description: 'Seconds'),
                    ],
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not signed in',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '404',
                description: 'There is no websocket server',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function token(Request $request): JsonResponse
    {
        abort_unless(WebsocketServer::enabled(), 404, 'There is no websocket server');

        return response()->json(['token' => WebsocketServer::issueToken($request->user()), 'expiresIn' => 120]);
    }

    /**
     * For the websocket server only (not part of the public API): who a browser's token belongs to. Spends the token
     */
    public function validateToken(Request $request): JsonResponse
    {
        $key = config('services.websocket.key');
        abort_if($key === null || !hash_equals($key, (string) $request->bearerToken()), 403);

        $token = $request->input('token');
        $user = is_string($token) && $token !== '' ? WebsocketServer::userForToken($token) : null;
        abort_if($user === null, 404, 'Unknown or used token');

        return response()->json(['id' => $user->id, 'name' => $user->name, 'role' => $user->role->value]);
    }
}
