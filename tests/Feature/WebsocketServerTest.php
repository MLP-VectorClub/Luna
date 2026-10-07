<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebsocketServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.websocket' => ['host' => 'https://ws.example', 'url' => 'http://127.0.0.1:3672', 'key' => 'shared-secret']]);
    }

    public function testTheConfigNamesTheServerOnlyWhenThereIsOne(): void
    {
        $this->getJson('/config')->assertOk()->assertJsonPath('wsServerHost', 'https://ws.example');
        Cache::flush();
        config(['services.websocket.host' => null]);
        $this->getJson('/config')->assertOk()->assertJsonPath('wsServerHost', null);
    }

    public function testASignedInUserGetsATokenTheServerCanSpendOnce(): void
    {
        $user = User::factory()->create(['role' => Role::Member]);
        $this->getJson('/users/me/socket-token')->assertStatus(405);
        $this->postJson('/users/me/socket-token')->assertUnauthorized();

        $this->actingAs($user, 'sanctum');
        $token = $this->postJson('/users/me/socket-token')->assertOk()->assertJsonPath('expiresIn', 120)->json('token');
        $this->assertNotSame($token, $this->postJson('/users/me/socket-token')->json('token'));

        $this->postJson('/internal/websocket/validate', ['token' => $token])->assertForbidden();
        $this->withToken('wrong')->postJson('/internal/websocket/validate', ['token' => $token])->assertForbidden();
        $this->withToken('shared-secret')->postJson('/internal/websocket/validate', ['token' => $token])->assertOk()
            ->assertExactJson(['id' => $user->id, 'name' => $user->name, 'role' => 'member']);
        $this->withToken('shared-secret')->postJson('/internal/websocket/validate', ['token' => $token])->assertNotFound();
        $this->withToken('shared-secret')->postJson('/internal/websocket/validate', ['token' => 'made-up'])->assertNotFound();
        $this->withToken('shared-secret')->postJson('/internal/websocket/validate', [])->assertNotFound();
    }

    public function testThereIsNoTokenWithoutAServer(): void
    {
        config(['services.websocket' => ['host' => null, 'url' => null, 'key' => null]]);
        $this->actingAs(User::factory()->create(['role' => Role::User]), 'sanctum');

        $this->postJson('/users/me/socket-token')->assertNotFound();
        $this->postJson('/internal/websocket/validate', ['token' => 'x'])->assertForbidden();
    }

    public function testNotificationsAreAnnouncedToTheServer(): void
    {
        Http::fake(['127.0.0.1:3672/*' => Http::response('', 204)]);
        $user = User::factory()->create(['role' => Role::User]);

        $notification = Notification::send($user->id, 'post-approved', ['id' => 5]);
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:3672/notify' && $request->hasHeader('Authorization', 'Bearer shared-secret') && $request['user'] === (string) $user->id);

        $announced = fn () => count(Http::recorded(fn ($request) => str_ends_with($request->url(), '/notify')));
        $before = $announced();
        $this->actingAs($user, 'sanctum')->postJson("/notifications/{$notification->id}/read")->assertNoContent();
        $this->assertSame(1, $announced() - $before, 'reading it tells the server once');
        // Reading it again changes nothing
        $this->postJson("/notifications/{$notification->id}/read")->assertNoContent();
        $this->assertSame(1, $announced() - $before);
    }

    public function testAServerThatIsDownDoesNotBreakNotifications(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));
        $user = User::factory()->create(['role' => Role::User]);

        $this->assertNotNull(Notification::send($user->id, 'post-approved', ['id' => 5])->id);
    }

    public function testNothingIsSentWithoutAServerAddress(): void
    {
        config(['services.websocket.url' => null]);
        Http::fake();
        Notification::send(User::factory()->create(['role' => Role::User])->id, 'post-approved', ['id' => 5]);

        Http::assertNothingSent();
    }
}
