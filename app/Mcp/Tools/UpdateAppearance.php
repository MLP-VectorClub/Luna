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

#[Name('update_appearance')]
#[Description('Change the label, notes or privacy of an appearance. Only what the key owner may edit succeeds.')]
#[IsIdempotent]
class UpdateAppearance extends ApiTool
{
    protected function scopes(): array
    {
        return [ApiKeyScope::AppearancesWrite];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->min(0)->required(),
            'label' => $schema->string()->max(70),
            'notes' => $schema->string()->description('Notes, in the site\'s markup'),
            'private' => $schema->boolean(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['id' => ['required', 'integer', 'min:0'], 'label' => ['nullable', 'string', 'max:70'], 'notes' => ['nullable', 'string'], 'private' => ['nullable', 'boolean']]);
        $id = $data['id'];
        unset($data['id']);

        return $this->call('PUT', '/appearances/' . $id, array_filter($data, fn($v) => $v !== null));
    }
}
