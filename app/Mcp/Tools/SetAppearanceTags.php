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

#[Name('set_appearance_tags')]
#[Description('Replace the tags of an appearance with a comma-separated list. Pass the previous list as origTags to avoid overwriting concurrent edits.')]
#[IsIdempotent]
class SetAppearanceTags extends ApiTool
{
    protected function scopes(): array
    {
        return [ApiKeyScope::AppearancesWrite];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->min(0)->required(),
            'tags' => $schema->string()->description('Comma-separated tag names')->required(),
            'origTags' => $schema->string()->description('The comma-separated list you started from'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['id' => ['required', 'integer', 'min:0'], 'tags' => ['present', 'nullable', 'string'], 'origTags' => ['nullable', 'string']]);
        $id = $data['id'];
        unset($data['id']);

        return $this->call('PUT', '/appearances/' . $id . '/tags', array_filter($data, fn($v) => $v !== null));
    }
}
