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

#[Name('list_tags')]
#[Description('List the tags used on appearances, with usage counts.')]
#[IsReadOnly]
class ListTags extends ApiTool
{
    protected function scopes(): array
    {
        return [ApiKeyScope::Read, ApiKeyScope::ReadOwn];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1),
            'size' => $schema->integer()->min(1)->max(100),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'size' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $this->call('GET', '/tags', array_filter($data, fn($v) => $v !== null));
    }
}
