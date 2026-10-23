<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\FakesPwnedPasswords;
use Tests\TestCase;

class ApiKeysTest extends TestCase
{
    use RefreshDatabase;
    use FakesPwnedPasswords;

    private function user(Role $role = Role::Member): User
    {
        return User::factory()->create(['role' => $role, 'password' => Hash::make('hunter2hunter2')]);
    }

    private function create(User $user, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($user, 'sanctum');

        return $this->postJson('/users/me/api-keys', $extra + ['name' => 'CI', 'scopes' => ['read'], 'currentPassword' => 'hunter2hunter2']);
    }

    public function testCreatingShowsTheSecretOnceAndStoresOnlyAHash(): void
    {
        $user = $this->user();
        $response = $this->create($user, ['expiresInDays' => 30])->assertCreated();
        $secret = $response->json('secret');
        $this->assertStringContainsString(ApiKeyService::TOKEN_PREFIX, $secret);
        $this->assertNotNull($response->json('key.expiresAt'));
        $this->assertSame(['read'], $response->json('key.scopes'));
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $secret]);

        $list = $this->getJson('/users/me/api-keys')->assertOk();
        $this->assertCount(1, $list->json());
        $this->assertStringNotContainsString($secret, $list->getContent());
    }

    public function testCreatingNeedsThePasswordAndValidScopes(): void
    {
        $user = $this->user();
        $this->create($user, ['currentPassword' => 'nope'])->assertUnprocessable();
        $this->create($user, ['scopes' => ['admin']])->assertUnprocessable();
        $this->create($user, ['scopes' => []])->assertUnprocessable();
        $this->create($user, ['expiresInDays' => 7])->assertUnprocessable();
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function testKeysAreCapped(): void
    {
        $user = $this->user();
        for ($i = 0; $i < ApiKeyService::MAX_KEYS_PER_USER; $i++) {
            $this->create($user, ['name' => "k$i"])->assertCreated();
        }
        $this->create($user)->assertUnprocessable();
    }

    public function testDeviceTokensAndKeysStayApart(): void
    {
        $user = $this->user();
        $user->createToken('Firefox');
        $this->create($user)->assertCreated();
        $key = PersonalAccessToken::apiKeys()->firstOrFail();

        $this->assertCount(1, $this->getJson('/users/tokens')->json('tokens'));
        $this->deleteJson('/users/tokens/' . $key->id)->assertNotFound();
        $this->assertCount(1, $this->getJson('/users/me/api-keys')->json());
    }

    public function testRotatingReplacesTheSecret(): void
    {
        $user = $this->user();
        $old = $this->create($user, ['name' => 'Bot', 'scopes' => ['read', 'appearances:write']])->json();
        $new = $this->postJson("/users/me/api-keys/{$old['key']['id']}/rotate", ['currentPassword' => 'hunter2hunter2'])->assertOk()->json();

        $this->assertNotSame($old['secret'], $new['secret']);
        $this->assertSame('Bot', $new['key']['name']);
        $this->assertSame(['read', 'appearances:write'], $new['key']['scopes']);
        $this->assertCount(1, $this->getJson('/users/me/api-keys')->json());
    }

    public function testOnlyTheOwnerCanRotateOrRevoke(): void
    {
        $owner = $this->user();
        $id = $this->create($owner)->json('key.id');
        $this->actingAs($this->user(), 'sanctum');
        $this->postJson("/users/me/api-keys/$id/rotate", ['currentPassword' => 'hunter2hunter2'])->assertNotFound();
        $this->deleteJson("/users/me/api-keys/$id")->assertNotFound();
        $this->assertSame(1, PersonalAccessToken::count());

        $this->actingAs($owner, 'sanctum');
        $this->deleteJson("/users/me/api-keys/$id")->assertNoContent();
        $this->assertSame(0, PersonalAccessToken::count());
    }

    private function withKey(User $user, array $scopes): string
    {
        $secret = app(ApiKeyService::class)->create($user, 'test', $scopes, null)->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $secret;
    }

    public function testScopesNarrowWhatAKeyMayDo(): void
    {
        $user = $this->user();
        $read = $this->withKey($user, ['read']);

        $this->withToken($read)->getJson('/config')->assertOk();
        // Needs the owner-level scope
        $this->withToken($read)->getJson('/notifications')->assertForbidden();
        // No write scope
        $this->withToken($read)->postJson('/posts', [])->assertForbidden();
        // Keys cannot manage keys or sessions
        $this->withToken($read)->getJson('/users/me/api-keys')->assertForbidden();
        $this->withToken($read)->getJson('/users/tokens')->assertForbidden();
        $this->withToken($this->withKey($user, ['read:own']))->getJson('/notifications')->assertOk();
    }

    public function testAKeyNeverExceedsItsOwnersRole(): void
    {
        $key = $this->withKey($this->user(Role::User), ['read', 'appearances:write']);
        $this->withToken($key)->putJson('/appearances/order', [])->assertForbidden();
    }

    public function testDeviceTokensAreNotRestricted(): void
    {
        $token = $this->user()->createToken('Firefox')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/users/tokens')->assertOk();
    }

    public function testRevokedKeysStopWorking(): void
    {
        $user = $this->user();
        $secret = $this->withKey($user, ['read:own']);
        $this->withToken($secret)->getJson('/notifications')->assertOk();
        PersonalAccessToken::apiKeys()->delete();
        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->getJson('/notifications')->assertUnauthorized();
    }
}
