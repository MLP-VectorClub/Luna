<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuestSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The sessions of the other tests are in memory, these are about the rows
        config(['session.driver' => 'database']);
    }

    private function frontEnd(): static
    {
        return $this->withHeaders(['Origin' => config('app.frontend_url'), 'Referer' => config('app.frontend_url').'/']);
    }

    private function sessions(): int
    {
        return DB::table(config('session.table'))->count();
    }

    public function testAGuestWhoOnlyReadsStartsNoSession(): void
    {
        foreach (['/config', '/notices/current', '/useful-links/sidebar', '/show/upcoming', '/users/me'] as $path) {
            $this->frontEnd()->getJson($path);
        }
        $this->frontEnd()->getJson('/config')->assertOk()->assertCookieMissing(config('session.cookie'))->assertCookieMissing('XSRF-TOKEN');

        $this->assertSame(0, $this->sessions());
    }

    public function testTheCsrfHandshakeStartsTheSession(): void
    {
        $this->frontEnd()->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie(config('session.cookie'))->assertCookie('XSRF-TOKEN');

        $this->assertSame(1, $this->sessions());
    }

    public function testAVisitorWithASessionCookieKeepsTheirSession(): void
    {
        $response = $this->frontEnd()->get('/sanctum/csrf-cookie');
        $cookie = $response->getCookie(config('session.cookie'), false);

        $this->frontEnd()->withUnencryptedCookie(config('session.cookie'), $cookie->getValue())->withCredentials()->getJson('/config')->assertOk();

        $this->assertSame(1, $this->sessions());
    }

    public function testASignInGoesThroughTheHandshakeAsBefore(): void
    {
        $user = User::factory()->create(['email' => 'in@example.com']);

        $handshake = $this->frontEnd()->get('/sanctum/csrf-cookie');
        $session = $handshake->getCookie(config('session.cookie'), false)->getValue();
        $xsrf = $handshake->getCookie('XSRF-TOKEN')->getValue();
        $this->assertSame(1, $this->sessions());

        $this->frontEnd()
            ->withUnencryptedCookie(config('session.cookie'), $session)
            ->withHeader('X-XSRF-TOKEN', $xsrf)
            ->withCredentials()
            ->postJson('/users/signin', ['email' => $user->email, 'password' => 'wrong-password-1'])
            ->assertStatus(401);

        // The same session served the sign in, no second one was started for it
        $this->assertSame(1, $this->sessions());
    }

    public function testAGuestWithARememberCookieIsNotSkipped(): void
    {
        $this->frontEnd()->withUnencryptedCookie('remember_web_abc', 'x')->withCredentials()->getJson('/config')->assertOk();

        $this->assertSame(1, $this->sessions());
    }

    public function testWritesAlwaysStartASession(): void
    {
        $this->frontEnd()->postJson('/users/signin', ['email' => 'nobody@example.com', 'password' => 'whatever']);

        $this->assertSame(1, $this->sessions());
    }

    public function testGuestSessionsAreDeletedAfterADayAndSignedInOnesAreNot(): void
    {
        $user = User::factory()->create();
        $row = fn(string $id, ?int $userId, int $ago) => ['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => now()->subHours($ago)->getTimestamp()];
        DB::table(config('session.table'))->insert([
            $row('guest-fresh', null, 2),
            $row('guest-old', null, 30),
            $row('user-old', $user->id, 400),
        ]);

        $this->artisan('sessions:prune-guests')->expectsOutputToContain('1 guest session deleted')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['guest-fresh', 'user-old'], DB::table(config('session.table'))->pluck('id')->all());
    }
}
