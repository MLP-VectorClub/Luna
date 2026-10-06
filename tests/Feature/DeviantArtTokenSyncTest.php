<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DeviantartUser;
use App\Models\User;
use App\Utils\DeviantArtTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeviantArtTokenSyncTest extends TestCase
{
    use RefreshDatabase;

    private const DA_ID = 'c9a2b1d0-0000-4000-8000-000000000001';

    private function member(array $da = []): array
    {
        $user = User::factory()->create(['role' => Role::Member, 'password' => Hash::make('correct-horse-battery')]);
        $record = (new DeviantartUser(['name' => 'Linked', 'avatar_url' => 'https://example.com/a.png']))->forceFill([
            'id' => self::DA_ID,
            'user_id' => $user->id,
            'access' => 'old-access',
            'refresh' => 'old-refresh',
            'access_expires' => now()->subHour(),
            'updated_at' => now()->subDays(10),
        ] + $da);
        $record->save();
        $record->forceFill(['updated_at' => $da['updated_at'] ?? now()->subDays(10)])->saveQuietly();

        return [$user, $record];
    }

    private function fakeDeviantArt(int $status, array $body = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([DeviantArtTokens::TOKEN_URL => Http::response($body, $status)]);
    }

    public function testNothingHappensWhileTheSyncIsOff(): void
    {
        [, $record] = $this->member();
        Http::fake();

        $this->artisan('deviantart:refresh-tokens')->expectsOutput('DEVIANTART_TOKEN_SYNC is off, nothing done')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('old-refresh', $record->fresh()->refresh);
        $this->postJson('/users/signin', ['email' => $record->user->email, 'password' => 'correct-horse-battery'])->assertOk();
    }

    public function testTheCommandRefreshesTokensThatWereNotRefreshedForAWhile(): void
    {
        config(['services.deviantart.token_sync' => true]);
        [, $record] = $this->member();
        $this->fakeDeviantArt(200, ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]);

        $this->artisan('deviantart:refresh-tokens')->expectsOutput('1 refreshed, 0 signed out, 0 skipped (DeviantArt unavailable)')->assertSuccessful();

        $record = $record->fresh();
        $this->assertSame('new-access', $record->access);
        $this->assertSame('new-refresh', $record->refresh);
        $this->assertTrue($record->access_expires->isFuture());
        Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'old-refresh');
    }

    public function testRecentlyRefreshedTokensAreLeftAlone(): void
    {
        config(['services.deviantart.token_sync' => true]);
        $this->member(['updated_at' => now()->subDay()]);
        Http::fake();

        $this->artisan('deviantart:refresh-tokens')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function testARefusedTokenSignsTheUserOutEverywhere(): void
    {
        config(['services.deviantart.token_sync' => true, 'session.driver' => 'database']);
        [$user, $record] = $this->member();
        $user->createToken('test');
        DB::table(config('session.table'))->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->fakeDeviantArt(400, ['error' => 'invalid_grant']);

        $this->artisan('deviantart:refresh-tokens')->expectsOutput('0 refreshed, 1 signed out, 0 skipped (DeviantArt unavailable)')->assertSuccessful();

        $record = $record->fresh();
        $this->assertNull($record->refresh);
        $this->assertNull($record->access);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table(config('session.table'))->where('user_id', $user->id)->count());
    }

    public function testDeviantArtOutagesDoNotSignAnyoneOut(): void
    {
        config(['services.deviantart.token_sync' => true]);
        [$user, $record] = $this->member();
        $user->createToken('test');
        $this->fakeDeviantArt(503);

        $this->artisan('deviantart:refresh-tokens')->expectsOutput('0 refreshed, 0 signed out, 1 skipped (DeviantArt unavailable)')->assertSuccessful();

        $this->assertSame('old-refresh', $record->fresh()->refresh);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function testSigningInByEmailRefreshesTheTokenFirst(): void
    {
        config(['services.deviantart.token_sync' => true]);
        [$user, $record] = $this->member();
        $this->fakeDeviantArt(200, ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]);

        $this->postJson('/users/signin', ['email' => $user->email, 'password' => 'correct-horse-battery'])->assertOk()->assertJsonStructure(['token']);
        $this->assertSame('new-refresh', $record->fresh()->refresh);
    }

    public function testSigningInByEmailNeedsDeviantArtWhenTheTokenIsDead(): void
    {
        config(['services.deviantart.token_sync' => true]);
        [$user, $record] = $this->member();
        $this->fakeDeviantArt(400, ['error' => 'invalid_grant']);

        $this->postJson('/users/signin', ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertForbidden()->assertJsonPath('deviantArtRequired', true);
        $this->assertNull($record->fresh()->refresh);

        // Without any refresh token there is nothing left to ask DeviantArt about
        Http::fake();
        $this->postJson('/users/signin', ['email' => $user->email, 'password' => 'correct-horse-battery'])->assertForbidden();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'deviantart.com'));
    }

    public function testSigningInByEmailAsksToTryAgainWhenDeviantArtIsDown(): void
    {
        config(['services.deviantart.token_sync' => true]);
        [$user] = $this->member();
        $this->fakeDeviantArt(500);

        $this->postJson('/users/signin', ['email' => $user->email, 'password' => 'correct-horse-battery'])->assertStatus(503);
    }

    public function testTheCurrentUserCheckRenewsTheSessionOrEndsIt(): void
    {
        config(['services.deviantart.token_sync' => true]);
        [$user, $record] = $this->member();
        $this->actingAs($user, 'sanctum');

        $this->fakeDeviantArt(200, ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]);
        $this->getJson('/users/me')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertSame('new-refresh', $record->fresh()->refresh);

        // The token is valid now, DeviantArt is not asked again
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake();
        $this->getJson('/users/me')->assertOk();
        Http::assertNothingSent();

        DB::table('deviantart_users')->where('id', self::DA_ID)->update(['access_expires' => now()->subMinute()]);
        $user->unsetRelation('daUser'); // the test reuses one user object for every request
        $this->fakeDeviantArt(400, ['error' => 'invalid_grant']);
        $this->getJson('/users/me')->assertUnauthorized()->assertJsonPath('deviantArtRequired', true);
        $this->assertNull($record->fresh()->refresh);
    }

    public function testUsersWithoutADeviantArtLinkSignInAsBefore(): void
    {
        config(['services.deviantart.token_sync' => true]);
        $user = User::factory()->create(['role' => Role::Staff, 'password' => Hash::make('correct-horse-battery')]);
        Http::fake();

        $this->postJson('/users/signin', ['email' => $user->email, 'password' => 'correct-horse-battery'])->assertOk();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'deviantart.com'));
    }
}
