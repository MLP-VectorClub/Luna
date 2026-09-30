<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutTest extends TestCase
{
    use RefreshDatabase;

    public function testServerInfoDescribesTheRequest(): void
    {
        // The endpoint reads the raw superglobals, which the test client doesn't fill in
        $_SERVER['HTTP_USER_AGENT'] = 'Luna-Test-Agent';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        try {
            $this->getJson('/about/server-info')
                ->assertOk()
                ->assertJsonStructure(['commitId', 'commitTime', 'ip', 'proxiedIps', 'userAgent', 'deviceIdentifier'])
                ->assertJsonPath('userAgent', 'Luna-Test-Agent')
                ->assertJsonPath('ip', '203.0.113.7');
        } finally {
            unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR']);
        }
    }

    public function testConnectionIsAnAliasOfServerInfo(): void
    {
        $this->getJson('/about/connection')
            ->assertOk()
            ->assertJsonStructure(['ip', 'userAgent', 'deviceIdentifier']);
    }

    public function testMembersListsEveryoneAboveTheUserRole(): void
    {
        User::factory()->create(['name' => 'plain_user', 'role' => Role::User]);
        User::factory()->create(['name' => 'zed_staff', 'role' => Role::Staff]);
        User::factory()->create(['name' => 'alpha_member', 'role' => Role::Member]);

        $response = $this->getJson('/about/members')->assertOk();

        // Ordered by name, regular users are left out and private fields never leak
        $this->assertSame(['alpha_member', 'zed_staff'], array_column($response->json(), 'name'));
        $response->assertJsonMissingPath('0.email')->assertJsonMissingPath('0.password');
    }
}
