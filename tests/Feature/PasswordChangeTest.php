<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\FakesPwnedPasswords;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;
    use FakesPwnedPasswords;

    private function userWithPassword(Role $role = Role::Staff, ?string $password = 'old-password-1'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->forceFill(['password' => $password === null ? null : Hash::make($password)])->save();

        return $user;
    }

    public function testPermissions(): void
    {
        $this->postJson('/users/me/password', ['newPassword' => 'brand-new-password'])->assertUnauthorized();
        $this->actingAs($this->userWithPassword(Role::Member), 'sanctum');
        $this->postJson('/users/me/password', ['currentPassword' => 'old-password-1', 'newPassword' => 'brand-new-password'])->assertForbidden();
    }

    public function testCurrentPasswordIsChecked(): void
    {
        $user = $this->userWithPassword();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/users/me/password', ['newPassword' => 'brand-new-password'])->assertJsonValidationErrors('currentPassword');
        $this->postJson('/users/me/password', ['currentPassword' => 'wrong', 'newPassword' => 'brand-new-password'])->assertJsonValidationErrors('currentPassword');
        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
    }

    public function testNewPasswordValidation(): void
    {
        $this->actingAs($this->userWithPassword(), 'sanctum');

        $this->postJson('/users/me/password', ['currentPassword' => 'old-password-1'])->assertJsonValidationErrors('newPassword');
        $this->postJson('/users/me/password', ['currentPassword' => 'old-password-1', 'newPassword' => 'short'])->assertJsonValidationErrors('newPassword');
        $this->postJson('/users/me/password', ['currentPassword' => 'old-password-1', 'newPassword' => str_repeat('a', 301)])->assertJsonValidationErrors('newPassword');

        $this->fakePwnedPassword('password12345', 5000);
        $this->postJson('/users/me/password', ['currentPassword' => 'old-password-1', 'newPassword' => 'password12345'])->assertJsonValidationErrors('newPassword');
    }

    public function testChangingThePasswordSignsEverySessionOut(): void
    {
        $user = $this->userWithPassword();
        $user->createToken('other device');
        $this->actingAs($user, 'sanctum');
        $this->fakePwnedPassword('brand-new-password', 0);

        $this->postJson('/users/me/password', ['currentPassword' => 'old-password-1', 'newPassword' => 'brand-new-password'])->assertOk()->assertJsonStructure(['message']);

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function testAccountWithoutPasswordCanSetOneWithoutTheCurrentPassword(): void
    {
        $user = $this->userWithPassword(Role::Staff, null);
        $this->actingAs($user, 'sanctum');
        $this->fakePwnedPassword('first-password-1', 0);

        $this->postJson('/users/me/password', ['newPassword' => 'first-password-1'])->assertOk();
        $this->assertTrue(Hash::check('first-password-1', $user->fresh()->password));
    }
}
