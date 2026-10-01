<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ContractFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function testConfigIsPublicAndComplete(): void
    {
        $this->getJson('/config')
            ->assertOk()
            ->assertJsonStructure(['tagTypes', 'roles', 'showTypes', 'maxUploadSize', 'patterns' => ['printableAscii', 'hexColor', 'username', 'episodeTitle'], 'wsServerHost', 'discordInviteLink'])
            ->assertJsonPath('tagTypes.spec', 'Species')
            ->assertJsonPath('roles.staff', 'Staff')
            ->assertJsonPath('showTypes.movie', 'Movie')
            ->assertJsonPath('wsServerHost', null);
    }

    public function testConfigPatternsWorkAsPhpRegexes(): void
    {
        foreach ($this->getJson('/config')->json('patterns') as $name => $pattern) {
            $this->assertNotFalse(@preg_match('~'.str_replace('~', '\~', $pattern['source']).'~'.$pattern['flags'], ''), $name);
        }
        $hex = $this->getJson('/config')->json('patterns.hexColor');
        $this->assertSame(1, preg_match('~'.$hex['source'].'~'.$hex['flags'], '#ffaa00'));
    }

    public function testNotFoundIsAJsonMessage(): void
    {
        $this->getJson('/does-not-exist')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function testRoleMiddleware(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'role:staff'])->get('/__staff-only', fn() => ['ok' => true]);

        $this->getJson('/__staff-only')->assertUnauthorized();
        $this->actingAs($this->user(Role::Member), 'sanctum')->getJson('/__staff-only')
            ->assertForbidden()->assertJsonStructure(['message']);
        $this->actingAs($this->user(Role::Staff), 'sanctum')->getJson('/__staff-only')->assertOk();
        $this->actingAs($this->user(Role::Developer), 'sanctum')->getJson('/__staff-only')->assertOk();
    }

    public function testOptionalAuthFillsTheUserWithoutRejectingGuests(): void
    {
        Route::middleware(['api', 'optional.auth'])->get('/__who', fn(\Illuminate\Http\Request $r) => ['id' => $r->user()?->id]);

        $this->getJson('/__who')->assertOk()->assertJsonPath('id', null);

        $user = $this->user(Role::User);
        $token = $user->createToken('t')->plainTextToken;
        $this->withToken($token)->getJson('/__who')->assertOk()->assertJsonPath('id', $user->id);
    }

    public function testTestLoginReturnsAWorkingToken(): void
    {
        $user = $this->user(Role::User);

        $token = $this->postJson("/test/login/{$user->id}")->assertOk()->json('token');
        $this->withToken($token)->getJson('/users/me')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->postJson('/test/login/987654')->assertNotFound();
    }
}
