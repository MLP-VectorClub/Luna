<?php

namespace App\Mcp\Tools;

use App\Enums\ApiKeyScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_user')]
#[Description('Get the public profile of a user by ID.')]
#[IsReadOnly]
class GetUser extends ApiTool
{
    protected function scopes(): array
    {
        return [ApiKeyScope::Read, ApiKeyScope::ReadOwn];
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

        return $this->call('GET', '/users/' . $data['id'] . '/profile');
    }
}
