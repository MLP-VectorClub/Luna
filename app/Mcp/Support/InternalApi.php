<?php

namespace App\Mcp\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Calls the REST API from inside the process as the same API key, so MCP tools get its validation, permission checks, throttling and privacy gating
 * instead of reimplementing them
 */
class InternalApi
{
    /** @return array{status: int, body: mixed} */
    public static function call(string $method, string $uri, array $data = []): array
    {
        $outer = app('request');
        $sub = Request::create($uri, $method, $method === 'GET' ? $data : [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $outer->bearerToken(),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $outer->server('REMOTE_ADDR'),
        ], $method === 'GET' ? null : json_encode($data));
        $sub->headers->set('X-Forwarded-For', $outer->ip());

        try {
            $response = app('router')->dispatch($sub);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['status' => 422, 'body' => ['message' => $e->getMessage(), 'errors' => $e->errors()]];
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return ['status' => $e->getStatusCode(), 'body' => ['message' => $e->getMessage() ?: SymfonyResponse::$statusTexts[$e->getStatusCode()] ?? 'Error']];
        } finally {
            app()->instance('request', $outer);
        }

        $body = $response->getContent();
        $decoded = $body === '' || $body === false ? null : json_decode($body, true);

        return ['status' => $response->getStatusCode(), 'body' => $decoded ?? $body];
    }
}
