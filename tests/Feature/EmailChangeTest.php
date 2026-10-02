<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\VerifyEmailAddress;
use App\Models\BlockedEmail;
use App\Models\EmailVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.email_dns_check' => false]);
    }

    private function staff(?string $password = 'old-password-1', array $extra = []): User
    {
        $user = User::factory()->create(['role' => Role::Staff] + $extra);
        $user->forceFill(['password' => $password === null ? null : Hash::make($password)])->save();

        return $user;
    }

    public function testPermissions(): void
    {
        $user = $this->staff();
        $this->postJson("/users/{$user->id}/email-changes", ['newEmail' => 'new@example.com'])->assertUnauthorized();
        $this->postJson('/users/email/verify', [])->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => Role::Member]), 'sanctum');
        $this->postJson("/users/{$user->id}/email-changes", ['newEmail' => 'new@example.com'])->assertForbidden();
        $this->postJson('/users/email/verify', [])->assertForbidden();

        $this->actingAs($user, 'sanctum');
        $this->postJson('/users/987654/email-changes', ['newEmail' => 'new@example.com'])->assertNotFound();
    }

    public function testRequestingAChangeOfYourOwnAddress(): void
    {
        Mail::fake();
        $user = $this->staff();
        $this->actingAs($user, 'sanctum');

        $this->postJson("/users/{$user->id}/email-changes", ['newEmail' => 'new@example.com'])->assertJsonValidationErrors('currentPassword');
        $this->postJson("/users/{$user->id}/email-changes", ['newEmail' => 'new@example.com', 'currentPassword' => 'wrong'])->assertJsonValidationErrors('currentPassword');
        $this->postJson("/users/{$user->id}/email-changes", [])->assertJsonValidationErrors('newEmail');
        $this->postJson("/users/{$user->id}/email-changes", ['newEmail' => 'ab'])->assertJsonValidationErrors('newEmail');
        $this->postJson("/users/{$user->id}/email-changes", ['newEmail' => $user->email, 'currentPassword' => 'old-password-1'])->assertJsonValidationErrors('newEmail');
        $this->postJson("/users/{$user->id}/email-changes", ['newEmail' => 'not an address', 'currentPassword' => 'old-password-1'])->assertJsonValidationErrors('newEmail');
        Mail::assertNothingSent();

        $this->postJson("/users/{$user->id}/email-changes", ['newEmail' => 'new@example.com', 'currentPassword' => 'old-password-1'])->assertOk()->assertJsonStructure(['message']);
        Mail::assertSent(VerifyEmailAddress::class, fn($mail) => $mail->hasTo('new@example.com') && strlen($mail->verification->hash) === 128);
        $this->assertSame($user->email, $user->fresh()->email);

        $this->postJson("/users/{$user->id}/email-changes", ['newEmail' => 'new@example.com', 'currentPassword' => 'old-password-1'])->assertStatus(429);
    }

    public function testNeedsAPasswordFirstAndAddressesMustBeFree(): void
    {
        Mail::fake();
        $without_password = $this->staff(null);
        $this->actingAs($without_password, 'sanctum');
        $this->postJson("/users/{$without_password->id}/email-changes", ['newEmail' => 'new@example.com'])->assertStatus(409);

        $taken = User::factory()->create(['email' => 'taken@example.com']);
        $user = $this->staff();
        $this->actingAs($user, 'sanctum');
        $this->postJson("/users/{$user->id}/email-changes", ['newEmail' => 'taken@example.com', 'currentPassword' => 'old-password-1'])->assertJsonValidationErrors('newEmail');

        BlockedEmail::record('blocked@example.com');
        $this->postJson("/users/{$user->id}/email-changes", ['newEmail' => 'blocked@example.com', 'currentPassword' => 'old-password-1'])->assertJsonValidationErrors('newEmail');
        Mail::assertNothingSent();
    }

    public function testStaffCanRequestAChangeForAnotherUserWithoutThePassword(): void
    {
        Mail::fake();
        $target = User::factory()->create(['role' => Role::User]);
        $this->actingAs($this->staff(), 'sanctum');

        $this->postJson("/users/{$target->id}/email-changes", ['newEmail' => 'target@example.com'])->assertOk();
        Mail::assertSent(VerifyEmailAddress::class, fn($mail) => $mail->hasTo('target@example.com'));

        // Resending goes to the address on the account
        $this->postJson("/users/{$target->id}/email-changes", ['resend' => true])->assertOk();
        Mail::assertSent(VerifyEmailAddress::class, fn($mail) => $mail->hasTo($target->email));
    }

    public function testFailedDeliveryRemovesTheVerification(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $user = $this->staff();
        $this->actingAs($user, 'sanctum');

        $this->postJson("/users/{$user->id}/email-changes", ['newEmail' => 'new@example.com', 'currentPassword' => 'old-password-1'])->assertStatus(503);
        $this->assertSame(0, EmailVerification::count());
    }

    public function testVerifyingAndBlocking(): void
    {
        $user = $this->staff();
        $verification = EmailVerification::create(['user_id' => $user->id, 'email' => 'verified@example.com', 'hash' => bin2hex(random_bytes(64))]);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/users/email/verify', [])->assertJsonValidationErrors('hash');
        $this->postJson('/users/email/verify', ['hash' => 'short', 'action' => 'verify'])->assertJsonValidationErrors('hash');
        $this->postJson('/users/email/verify', ['hash' => str_repeat('a', 128), 'action' => 'verify'])->assertJsonValidationErrors('hash');
        $this->postJson('/users/email/verify', ['hash' => $verification->hash])->assertJsonValidationErrors('action');
        $this->postJson('/users/email/verify', ['hash' => $verification->hash, 'action' => 'nonsense'])->assertJsonValidationErrors('action');

        $this->postJson('/users/email/verify', ['hash' => $verification->hash, 'action' => 'verify'])->assertOk();
        $fresh = $user->fresh();
        $this->assertSame('verified@example.com', $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertSame(0, EmailVerification::count());
        $this->postJson('/users/email/verify', ['hash' => $verification->hash, 'action' => 'verify'])->assertJsonValidationErrors('hash');

        $block = EmailVerification::create(['user_id' => $user->id, 'email' => 'Spam@Example.com', 'hash' => bin2hex(random_bytes(64))]);
        $this->postJson('/users/email/verify', ['hash' => $block->hash, 'action' => 'block'])->assertOk();
        $this->assertTrue(BlockedEmail::isBlocked('spam@example.com'));
        $this->assertSame('verified@example.com', $user->fresh()->email);
    }

    public function testExpiredVerificationsAreRejected(): void
    {
        $user = $this->staff();
        $verification = EmailVerification::create(['user_id' => $user->id, 'email' => 'late@example.com', 'hash' => bin2hex(random_bytes(64))]);
        $verification->forceFill(['created_at' => now()->subHours(3)])->save();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/users/email/verify', ['hash' => $verification->hash, 'action' => 'verify'])->assertJsonValidationErrors('hash');
        $this->assertNotSame('late@example.com', $user->fresh()->email);
    }

    public function testTheMailContainsTheLinks(): void
    {
        config(['app.frontend_url' => 'https://front.example']);
        $user = $this->staff();
        $verification = EmailVerification::create(['user_id' => $user->id, 'email' => 'mail@example.com', 'hash' => str_repeat('a', 128)]);

        $html = (new VerifyEmailAddress($verification))->render();
        $this->assertStringContainsString('https://front.example/users/verify?hash='.str_repeat('a', 128).'&amp;action=verify', $html);
        $this->assertStringContainsString('action=block', $html);
    }
}
