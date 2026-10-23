<?php

namespace App\Mcp\Tools;

use App\Enums\ApiKeyScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('reserve_post')]
#[Description('Reserve a request post so you can work on it. Requires club membership.')]
#[IsIdempotent]
class ReservePost extends ApiTool
{
    protected function scopes(): array
    {
        return [ApiKeyScope::PostsWrite];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->min(1)->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['id' => ['required', 'integer', 'min:1']]);

        return $this->call('POST', '/posts/' . $data['id'] . '/reservation');
    }
}
