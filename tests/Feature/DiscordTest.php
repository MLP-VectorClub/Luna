<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DiscordMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class DiscordTest extends TestCase
{
    use RefreshDatabase;

    private function member(User $user, array $attributes = []): DiscordMember
    {
        $member = new DiscordMember($attributes + ['username' => 'old', 'discriminator' => 0, 'access' => 'tok', 'refresh' => 'ref', 'scope' => 'identify', 'expires' => now()->addDay()]);
        $member->forceFill(['id' => '123456789012345678', 'user_id' => $user->id])->save();

        return $member;
    }

    public function testPermissions(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $this->postJson("/users/{$user->id}/discord/sync")->assertUnauthorized();
        $this->deleteJson("/users/{$user->id}/discord")->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => Role::User]), 'sanctum');
        $this->postJson("/users/{$user->id}/discord/sync")->assertForbidden();
        $this->deleteJson("/users/{$user->id}/discord")->assertForbidden();
        $this->postJson('/users/987654/discord/sync')->assertNotFound();
        $this->deleteJson("/users/{$user->id}/discord/sync")->assertStatus(405);
        $this->getJson("/users/{$user->id}/discord")->assertStatus(405);
    }

    private function discordCode(string $id = '777000111222333444'): void
    {
        $discord = new SocialiteUser();
        $discord->map(['id' => $id, 'name' => 'Crazy', 'nickname' => 'crazy']);
        $discord->setRaw(['username' => 'crazy', 'global_name' => 'Crazy One', 'discriminator' => '0', 'avatar' => 'abc']);
        $discord->accessTokenResponseBody = ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'scope' => 'identify email'];
        $driver = \Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('redirectUrl')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($discord);
        Socialite::shouldReceive('driver')->with('discord')->andReturn($driver);
        config(['services.discord.guild_id' => '42']);
        Http::fake(['discord.com/api/v10/guilds/42/members/*' => Http::response(['nick' => 'Nicky', 'joined_at' => '2020-01-01T00:00:00+00:00'])]);
    }

    public function testLinkingAnAccount(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $this->postJson("/users/{$user->id}/discord", ['code' => 'abc'])->assertUnauthorized();
        $this->actingAs($user, 'sanctum');
        $this->postJson("/users/{$user->id}/discord", [])->assertUnprocessable()->assertJsonValidationErrors('code');
        $other = User::factory()->create(['role' => Role::User]);
        $this->postJson("/users/{$other->id}/discord", ['code' => 'abc'])->assertForbidden();

        $this->discordCode();
        $this->postJson("/users/{$user->id}/discord", ['code' => 'abc'])->assertNoContent();
        $member = DiscordMember::find('777000111222333444');
        $this->assertSame($user->id, (int) $member->user_id);
        $this->assertSame('new-access', $member->access);
        $this->assertSame('Crazy One', $member->display_name);
        $this->assertSame('Nicky', $member->nick);
        $this->assertNotNull($member->last_synced);

        $this->getJson("/users/{$user->id}/profile")->assertOk()->assertJsonPath('discord.linked', true)->assertJsonPath('discord.serverMember', true);
        $this->postJson("/users/{$user->id}/discord", ['code' => 'abc'])->assertStatus(409);
    }

    public function testLinkingTakesOverAnUnboundServerMemberAndReplacesAnotherBoundRecord(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $this->member($user, ['access' => null, 'refresh' => null]);
        (new DiscordMember(['username' => 'unbound', 'discriminator' => 0]))->forceFill(['id' => '777000111222333444', 'user_id' => null])->save();
        $this->actingAs($user, 'sanctum');
        $this->discordCode();

        $this->postJson("/users/{$user->id}/discord", ['code' => 'abc'])->assertNoContent();
        $this->assertSame(1, DiscordMember::count());
        $this->assertSame($user->id, (int) DiscordMember::first()->user_id);
        $this->assertSame('777000111222333444', (string) DiscordMember::first()->id);
    }

    public function testAnAccountOfAnotherUserCannotBeTaken(): void
    {
        $owner = User::factory()->create(['role' => Role::User]);
        $this->member($owner)->forceFill(['id' => '777000111222333444'])->save();
        $user = User::factory()->create(['role' => Role::User]);
        $this->actingAs($user, 'sanctum');
        $this->discordCode();

        $this->postJson("/users/{$user->id}/discord", ['code' => 'abc'])->assertStatus(409);
        $this->assertSame($owner->id, (int) DiscordMember::first()->user_id);
    }

    public function testUnboundAndUnlinkedAccounts(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $this->actingAs($user, 'sanctum');
        $this->postJson("/users/{$user->id}/discord/sync")->assertStatus(409)->assertJsonStructure(['message']);
        $this->deleteJson("/users/{$user->id}/discord")->assertStatus(409);

        $this->member($user, ['access' => null, 'refresh' => null]);
        $this->postJson("/users/{$user->id}/discord/sync")->assertStatus(409);
    }

    public function testSyncAndCooldown(): void
    {
        config(['services.discord.guild_id' => '42']);
        $user = User::factory()->create(['role' => Role::User]);
        $member = $this->member($user);
        $this->actingAs($user, 'sanctum');
        Http::fake([
            'discord.com/api/v10/users/@me' => Http::response(['username' => 'new', 'global_name' => 'New Name', 'discriminator' => '0', 'avatar' => 'abc']),
            'discord.com/api/v10/guilds/42/members/*' => Http::response(['nick' => 'Nicky', 'joined_at' => '2020-01-01T00:00:00+00:00']),
        ]);

        $this->postJson("/users/{$user->id}/discord/sync")->assertNoContent();
        $member->refresh();
        $this->assertSame('new', $member->username);
        $this->assertSame('New Name', $member->display_name);
        $this->assertSame('Nicky', $member->nick);
        $this->assertNotNull($member->joined_at);

        $this->postJson("/users/{$user->id}/discord/sync")->assertStatus(429)->assertJsonPath('message', fn($m) => str_contains($m, '5 minutes'));
        $this->actingAs(User::factory()->create(['role' => Role::Staff]), 'sanctum');
        $this->postJson("/users/{$user->id}/discord/sync")->assertStatus(429);
    }

    public function testSyncOfRevokedAccountRemovesTheLink(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $this->member($user);
        $this->actingAs($user, 'sanctum');
        Http::fake(['discord.com/api/v10/users/@me' => Http::response(['message' => '401: Unauthorized'], 401)]);

        $this->postJson("/users/{$user->id}/discord/sync")->assertStatus(409);
        $this->assertSame(0, DiscordMember::count());
    }

    public function testUnlink(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $this->member($user);
        $this->actingAs($user, 'sanctum');
        Http::fake(['discord.com/api/v10/oauth2/token/revoke' => Http::response([], 200)]);

        $this->deleteJson("/users/{$user->id}/discord")->assertOk()->assertJsonPath('message', fn($m) => str_contains($m, 'unlinked'));
        Http::assertSent(fn($request) => str_contains($request->body(), 'token=ref'));
        $this->assertSame(0, DiscordMember::count());
        $this->deleteJson("/users/{$user->id}/discord")->assertStatus(409);
        $this->postJson("/users/{$user->id}/discord/sync")->assertStatus(409);
    }

    public function testUnlinkFailureKeepsTheMember(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $this->member($user);
        $this->actingAs($user, 'sanctum');
        Http::fake(['discord.com/api/v10/oauth2/token/revoke' => Http::response(['error' => 'server_error'], 500)]);

        $this->deleteJson("/users/{$user->id}/discord")->assertStatus(502);
        $this->assertSame(1, DiscordMember::count());
    }

    public function testTheProfileTellsTheLinkedAccountToTheUserAndStaffOnly(): void
    {
        $user = User::factory()->create(['role' => Role::Member]);
        $this->member($user, ['username' => 'someone', 'discriminator' => 42, 'joined_at' => now(), 'last_synced' => now()]);

        $this->getJson("/users/{$user->id}/profile")->assertOk()->assertJsonPath('discord', null);

        $this->actingAs($user, 'sanctum');
        $this->getJson("/users/{$user->id}/profile")->assertOk()
            ->assertJsonPath('discord.linked', true)->assertJsonPath('discord.tag', 'someone#0042')
            ->assertJsonPath('discord.serverMember', true)->assertJsonPath('discord.canSync', false)->assertJsonPath('discord.syncCooldown', 300)
            ->assertJsonPath('account.email', $user->email)->assertJsonPath('account.passwordSet', $user->password !== null && $user->password !== '');
        $this->actingAs(User::factory()->create(['role' => Role::Member]), 'sanctum');
        $this->getJson("/users/{$user->id}/profile")->assertOk()->assertJsonPath('account', null);
    }

    public function testOnlyTheUserAndDevelopersSeeTheEmailAddress(): void
    {
        $user = User::factory()->create(['role' => Role::Member, 'email' => 'private@example.com']);

        // Staff get the rest of the account section, not the address
        $this->actingAs(User::factory()->create(['role' => Role::Staff]), 'sanctum');
        $staff = $this->getJson("/users/{$user->id}/profile")->assertOk();
        $staff->assertJsonPath('account.email', null)->assertJsonPath('account.emailSet', true)->assertJsonStructure(['account' => ['emailVerifiedAt', 'passwordSet']]);
        $this->assertStringNotContainsString('private@example.com', $staff->getContent());

        $this->actingAs(User::factory()->create(['role' => Role::Admin]), 'sanctum');
        $this->getJson("/users/{$user->id}/profile")->assertOk()->assertJsonPath('account.email', null);

        $this->actingAs(User::factory()->create(['role' => Role::Developer]), 'sanctum');
        $this->getJson("/users/{$user->id}/profile")->assertOk()->assertJsonPath('account.email', 'private@example.com');

        $this->actingAs($user, 'sanctum');
        $this->getJson("/users/{$user->id}/profile")->assertOk()->assertJsonPath('account.email', 'private@example.com');
    }
}
