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

#[Name('get_config')]
#[Description('Site configuration: tag types, roles, show types, vector apps and other constants.')]
#[IsReadOnly]
class GetConfig extends ApiTool
{
    protected function scopes(): array
    {
        return [ApiKeyScope::Read, ApiKeyScope::ReadOwn];
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->call('GET', '/config');
    }
}
