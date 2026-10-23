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

#[Name('search_appearances')]
#[Description('Search a color guide for character/pony appearances by name or tag. Returns a page of results with pagination info.')]
#[IsReadOnly]
class SearchAppearances extends ApiTool
{
    protected function scopes(): array
    {
        return [ApiKeyScope::Read, ApiKeyScope::ReadOwn];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'guide' => $schema->string()->enum(['pony', 'eqg'])->description('Which guide: pony (Friendship is Magic) or eqg (Equestria Girls)')->required(),
            'q' => $schema->string()->description('Search text, matches names and tags'),
            'page' => $schema->integer()->min(1)->description('Page number, starting at 1'),
            'size' => $schema->integer()->min(1)->max(100)->description('Results per page'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['guide' => ['required', 'in:pony,eqg'], 'q' => ['nullable', 'string'], 'page' => ['nullable', 'integer', 'min:1'], 'size' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $this->call('GET', '/appearances', array_filter($data, fn($v) => $v !== null));
    }
}
