<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Http\Controllers\UsersController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\FakesPwnedPasswords;
use Tests\TestCase;

class BrowserSessionsTest extends TestCase
{
    use RefreshDatabase;
    use FakesPwnedPasswords;

    private const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0';

    private function storeSession(User $user, string $id, array $extra = []): void
    {
        DB::table('luna_sessions')->insert($extra + ['id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.5', 'user_agent' => self::FIREFOX, 'payload' => '', 'last_activity' => time()]);
    }

    public function testListingOnlyShowsTheOwnSessions(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $other = User::factory()->create(['role' => Role::User]);
        $this->storeSession($user, 'older-session', ['last_activity' => time() - 600]);
        $this->storeSession($user, 'newer-session');
        $this->storeSession($other, 'foreign-session');
        $this->getJson('/users/sessions')->assertUnauthorized();

        $this->actingAs($user, 'sanctum');
        $response = $this->getJson('/users/sessions')->assertOk();
        $this->assertSame([hash('sha256', 'newer-session'), hash('sha256', 'older-session')], array_column($response->json('sessions'), 'id'));
        $first = $response->json('sessions.0');
        $this->assertSame('Firefox 131 on GNU/Linux', $first['device']);
        $this->assertSame(self::FIREFOX, $first['userAgent']);
        $this->assertSame('203.0.113.5', $first['ip']);
        $this->assertFalse($first['current']);
        $this->assertNotNull($first['createdAt']);
        $this->assertStringNotContainsString('newer-session', $response->getContent());
    }

    public function testTheRequestingSessionIsMarkedAsCurrent(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $id = Str::random(40);
        $this->storeSession($user, $id);
        $this->storeSession($user, 'another-browser');

        // The request of a browser carries its session, which is what the controller compares with the stored ones
        $request = Request::create('/users/sessions');
        $store = app('session')->driver();
        $store->setId($id);
        $request->setLaravelSession($store);
        $request->setUserResolver(fn() => $user);

        $sessions = collect(app(UsersController::class)->sessions($request)->getData(true)['sessions']);
        $this->assertCount(1, $sessions->where('current', true));
        $this->assertSame(hash('sha256', $id), $sessions->firstWhere('current', true)['id']);
    }

    public function testEndingASession(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $other = User::factory()->create(['role' => Role::User]);
        $this->storeSession($user, 'to-end');
        $this->storeSession($user, 'to-keep');
        $this->storeSession($other, 'foreign');
        $this->deleteJson('/users/sessions/'.hash('sha256', 'to-end'))->assertUnauthorized();

        $this->actingAs($user, 'sanctum');
        $this->deleteJson('/users/sessions/'.hash('sha256', 'foreign'))->assertNotFound();
        $this->deleteJson('/users/sessions/'.hash('sha256', 'nothing'))->assertNotFound();
        $this->deleteJson('/users/sessions/not-a-hash')->assertNotFound();
        $this->deleteJson('/users/sessions/'.hash('sha256', 'to-end'))->assertNoContent();

        $this->assertEqualsCanonicalizing(['to-keep', 'foreign'], DB::table('luna_sessions')->pluck('id')->all());
    }

    public function testChangingThePasswordEndsEveryBrowserSession(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['role' => Role::Staff]);
        $user->forceFill(['password' => Hash::make('old-password-1')])->save();
        $this->storeSession($user, 'one');
        $this->storeSession($user, 'two');
        $this->storeSession(User::factory()->create(), 'foreign');
        $this->fakePwnedPassword('brand-new-password', 0);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/users/me/password', ['currentPassword' => 'old-password-1', 'newPassword' => 'brand-new-password'])->assertOk();

        $this->assertSame(['foreign'], DB::table('luna_sessions')->pluck('id')->all());
    }
}
