<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SigninTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPassword(string $password): User
    {
        return User::factory()->create(['password' => Hash::make($password)]);
    }

    public function testFieldsAreRequired(): void
    {
        $this->postJson('/users/signin', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function testUnknownEmailIsRejected(): void
    {
        $this->postJson('/users/signin', ['email' => 'nobody@example.com', 'password' => 'whatever123'])
            ->assertUnauthorized();
    }

    public function testWrongPasswordIsRejected(): void
    {
        $user = $this->userWithPassword('correct-horse-battery');

        $this->postJson('/users/signin', ['email' => $user->email, 'password' => 'wrong-horse-battery'])
            ->assertUnauthorized();
    }

    public function testAccountWithoutPasswordCannotUsePasswordSignin(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->postJson('/users/signin', ['email' => $user->email, 'password' => 'anything-at-all'])
            ->assertForbidden();
    }

    public function testCorrectPasswordReturnsAWorkingToken(): void
    {
        $user = $this->userWithPassword('correct-horse-battery');

        $token = $this->postJson('/users/signin', ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonStructure(['token'])
            ->json('token');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        // The token must actually authenticate, that goes through Sanctum's token guard
        $this->withToken($token)->getJson('/users/me')
            ->assertOk()
            ->assertJsonPath('name', $user->name)
            ->assertJsonPath('email', $user->email);
    }

    public function testTokenLifecycle(): void
    {
        $user = $this->userWithPassword('correct-horse-battery');
        $token = $this->postJson('/users/signin', ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->json('token');

        $tokens = $this->withToken($token)->getJson('/users/tokens')->assertOk();
        $this->assertCount(1, $tokens->json('tokens'));
        $this->assertNotNull($tokens->json('currentTokenId'));

        $this->withToken($token)->postJson('/users/signout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
