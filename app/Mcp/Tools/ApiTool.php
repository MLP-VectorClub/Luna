<?php

namespace App\Mcp\Tools;

use App\Enums\ApiKeyScope;
use App\Http\Middleware\EnforceApiKeyScopes;
use App\Mcp\Support\InternalApi;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/** A tool that is a thin wrapper around one REST endpoint, offered only to keys that carry its scope */
abstract class ApiTool extends Tool
{
    /** Any one of these scopes is enough to see and use the tool */
    abstract protected function scopes(): array;

    public function shouldRegister(HttpRequest $request): bool
    {
        $key = EnforceApiKeyScopes::keyOf($request);

        return $key !== null && collect($this->scopes())->contains(fn(ApiKeyScope $s) => $key->can($s->value));
    }

    protected function call(string $method, string $uri, array $data = []): Response|ResponseFactory
    {
        $result = InternalApi::call($method, $uri, $data);
        $message = is_array($result['body']) ? ($result['body']['message'] ?? null) : null;

        if ($result['status'] === 503) {
            return Response::error(($message ?? 'The site is read-only right now') . '. Try again in a minute');
        }
        if ($result['status'] >= 400) {
            $errors = is_array($result['body']) && isset($result['body']['errors']) ? ' ' . json_encode($result['body']['errors']) : '';

            return Response::error("HTTP {$result['status']}: " . ($message ?? 'Request failed') . $errors);
        }

        return Response::json($result['body']);
    }
}
