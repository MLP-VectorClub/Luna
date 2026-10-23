<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private function key(array $scopes, Role $role = Role::Member): string
    {
        $user = User::factory()->create(['role' => $role]);
        $secret = app(ApiKeyService::class)->create($user, 'mcp', $scopes, null)->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $secret;
    }

    private function rpc(string $method, array $params = [], ?string $token = null)
    {
        $request = $token === null ? $this : $this->withToken($token);

        return $request->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ]);
    }

    private function toolNames(string $token): array
    {
        $response = $this->rpc('tools/list', [], $token)->assertOk();

        return collect($response->json('result.tools'))->pluck('name')->sort()->values()->all();
    }

    public function testNoCredentialsAreRejected(): void
    {
        $this->rpc('tools/list')->assertUnauthorized();
    }

    public function testASignInTokenIsNotAnApiKey(): void
    {
        $token = User::factory()->create(['role' => Role::Member])->createToken('Firefox')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->rpc('tools/list', [], $token)->assertForbidden();
    }

    public function testToolsFollowTheScopesOfTheKey(): void
    {
        $this->assertSame(
            ['get_appearance', 'get_config', 'get_show', 'get_user', 'list_shows', 'list_tags', 'search_appearances'],
            $this->toolNames($this->key(['read'])),
        );
        $this->assertContains('update_appearance', $this->toolNames($this->key(['read', 'appearances:write'])));
        $this->assertNotContains('reserve_post', $this->toolNames($this->key(['read', 'appearances:write'])));
        $this->assertSame(['finish_post', 'reserve_post'], $this->toolNames($this->key(['posts:write'])));
    }

    public function testAReadToolReturnsTheApiResponse(): void
    {
        $response = $this->rpc('tools/call', ['name' => 'get_config', 'arguments' => (object) []], $this->key(['read']))->assertOk();
        $this->assertFalse($response->json('result.isError') ?? false);
        $this->assertArrayHasKey('tagTypes', json_decode($response->json('result.content.0.text'), true));
    }

    public function testApiErrorsBecomeToolErrors(): void
    {
        $response = $this->rpc('tools/call', ['name' => 'get_appearance', 'arguments' => ['id' => 999999]], $this->key(['read']))->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('404', $response->json('result.content.0.text'));
    }

    public function testAWriteToolHonoursTheRoleOfTheOwner(): void
    {
        $key = $this->key(['appearances:write'], Role::User);
        $response = $this->rpc('tools/call', ['name' => 'update_appearance', 'arguments' => ['id' => 1, 'label' => 'x']], $key)->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertMatchesRegularExpression('/HTTP (401|403|404)/', $response->json('result.content.0.text'));
    }
}
