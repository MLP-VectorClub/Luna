<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReadOnlyModeTest extends TestCase
{
    use RefreshDatabase;

    public function testReadsKeepWorkingAndTellTheFrontEnd(): void
    {
        config(['app.read_only' => true]);

        $this->getJson('/config')->assertOk()->assertJsonPath('readOnly', true);
        $this->getJson('/about/members')->assertOk();
    }

    public function testEverythingElseIsRefused(): void
    {
        config(['app.read_only' => true]);

        $this->postJson('/users/signin', ['email' => 'a@example.com', 'password' => 'whatever'])->assertStatus(503)
            ->assertJsonPath('message', 'The site is read-only right now, nothing can be changed')
            ->assertJsonPath('readOnly', true);
        $this->putJson('/appearances/1', [])->assertStatus(503);
        $this->deleteJson('/appearances/1')->assertStatus(503);
    }

    public function testTheWebsocketServerCanStillCheckTokens(): void
    {
        config(['app.read_only' => true]);

        $this->postJson('/internal/websocket/validate', [])->assertForbidden();
    }

    public function testUsingATokenDoesNotStoreWhenItWasUsed(): void
    {
        config(['app.read_only' => true]);
        $user = User::factory()->create(['role' => Role::User]);
        $token = $user->createToken('test');
        $this->assertNull($token->accessToken->fresh()->last_used_at);

        $this->withToken($token->plainTextToken)->getJson('/users/me')->assertOk();

        $this->assertNull($token->accessToken->fresh()->last_used_at);
    }

    public function testNothingIsRefusedWhenTheSiteIsNotReadOnly(): void
    {
        $this->getJson('/config')->assertOk()->assertJsonPath('readOnly', false);
        $this->postJson('/users/signin', ['email' => 'a@example.com', 'password' => 'whatever'])->assertUnauthorized();
    }
}
