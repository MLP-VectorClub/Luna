<?php

namespace Tests\Feature;

use App\Mail\ResetPassword;
use App\Models\BlockedEmail;
use App\Models\PasswordReset;
use App\Models\TwoFactor;
use App\Models\User;
use App\Utils\TwoFactorAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\FakesPwnedPasswords;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase, FakesPwnedPasswords;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
    }

    private function user(): User
    {
        return User::factory()->create(['password' => Hash::make('old-password-123')]);
    }

    /** The token of the link that was mailed to a user */
    private function tokenMailedTo(User $user): string
    {
        $token = null;
        Mail::assertSent(ResetPassword::class, function (ResetPassword $mail) use ($user, &$token) {
            $token = $mail->token;

            return $mail->hasTo($user->email);
        });

        return $token;
    }

    public function testAMemberGetsALink(): void
    {
        $user = $this->user();

        $this->postJson('/users/password/forgot', ['email' => $user->email])->assertStatus(202)->assertJsonStructure(['message']);

        $token = $this->tokenMailedTo($user);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        // Only the hash is kept
        $this->assertDatabaseHas('luna_password_resets', ['user_id' => $user->id, 'token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('luna_password_resets', ['token_hash' => $token]);
        $this->assertStringContainsString('/users/reset-password?token=' . $token, (new ResetPassword('x', $token))->render());
    }

    public function testTheAnswerIsTheSameForStrangers(): void
    {
        $member = $this->postJson('/users/password/forgot', ['email' => $this->user()->email]);
        $stranger = $this->postJson('/users/password/forgot', ['email' => 'nobody@example.com']);

        $stranger->assertStatus(202);
        $this->assertSame($member->json(), $stranger->json());
        Mail::assertSent(ResetPassword::class, 1);
    }

    public function testTheAddressIsFoundWhateverTheCase(): void
    {
        $user = $this->user();

        $this->postJson('/users/password/forgot', ['email' => '  ' . strtoupper($user->email) . ' '])->assertStatus(202);

        Mail::assertSent(ResetPassword::class);
    }

    public function testAnAddressIsRequired(): void
    {
        $this->postJson('/users/password/forgot', [])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function testOnlyAFewMailsAnHourGoToOneAddress(): void
    {
        $user = $this->user();

        for ($i = 0; $i < PasswordReset::MAILS_PER_HOUR + 2; $i++) {
            $this->postJson('/users/password/forgot', ['email' => $user->email])->assertStatus(202);
        }

        Mail::assertSent(ResetPassword::class, PasswordReset::MAILS_PER_HOUR);
    }

    public function testBlockedAddressesGetNothing(): void
    {
        $user = $this->user();
        BlockedEmail::create(['email' => strtolower($user->email)]);

        $this->postJson('/users/password/forgot', ['email' => $user->email])->assertStatus(202);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('luna_password_resets', 0);
    }

    public function testANewRequestReplacesTheOldLink(): void
    {
        $user = $this->user();
        $first = PasswordReset::issue($user);
        $second = PasswordReset::issue($user);

        $this->assertNull(PasswordReset::findValid($first));
        $this->assertNotNull(PasswordReset::findValid($second));
        $this->assertDatabaseCount('luna_password_resets', 1);
    }

    public function testTheLinkSetsANewPasswordOnceAndEndsEverySession(): void
    {
        $user = $this->user();
        $user->createToken('another device');
        $token = PasswordReset::issue($user);

        $this->postJson('/users/password/reset', ['token' => $token, 'newPassword' => 'a-brand-new-password-1'])
            ->assertOk()->assertJsonStructure(['message']);

        $this->assertTrue(Hash::check('a-brand-new-password-1', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('luna_password_resets', 0);
        $this->postJson('/users/signin', ['email' => $user->email, 'password' => 'a-brand-new-password-1'])->assertOk();

        $this->postJson('/users/password/reset', ['token' => $token, 'newPassword' => 'yet-another-password-2'])
            ->assertUnprocessable()->assertJsonValidationErrors(['token']);
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $user = $this->user();
        $token = PasswordReset::issue($user);

        $this->travel(PasswordReset::VALID_MINUTES + 1)->minutes();

        $this->postJson('/users/password/reset', ['token' => $token, 'newPassword' => 'a-brand-new-password-1'])
            ->assertUnprocessable()->assertJsonValidationErrors(['token']);
        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function testAForgedTokenIsRefused(): void
    {
        $this->user();
        PasswordReset::issue($this->user());

        $this->postJson('/users/password/reset', ['token' => str_repeat('a', 64), 'newPassword' => 'a-brand-new-password-1'])
            ->assertUnprocessable()->assertJsonValidationErrors(['token']);
        $this->postJson('/users/password/reset', ['token' => 'short', 'newPassword' => 'a-brand-new-password-1'])
            ->assertUnprocessable()->assertJsonValidationErrors(['token']);
    }

    public function testTheNewPasswordHasToBeAcceptable(): void
    {
        $user = $this->user();
        $token = PasswordReset::issue($user);

        $this->postJson('/users/password/reset', ['token' => $token, 'newPassword' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['newPassword']);
        // The link was not used up by the attempt
        $this->assertNotNull(PasswordReset::findValid($token));
    }

    public function testAnAccountWithoutAPasswordCanChooseOne(): void
    {
        $user = User::factory()->create(['password' => null]);
        $token = PasswordReset::issue($user);

        $this->postJson('/users/password/reset', ['token' => $token, 'newPassword' => 'a-brand-new-password-1'])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-password-1', $user->fresh()->password));
    }

    public function testTwoFactorStaysOnAfterAReset(): void
    {
        $user = $this->user();
        $secret = TwoFactorAuth::newSecret();
        TwoFactor::create(['user_id' => $user->id, 'secret' => $secret, 'confirmed_at' => now()]);
        $token = PasswordReset::issue($user);

        $this->postJson('/users/password/reset', ['token' => $token, 'newPassword' => 'a-brand-new-password-1'])->assertOk();

        // Mail access alone does not get past the second step
        $this->postJson('/users/signin', ['email' => $user->email, 'password' => 'a-brand-new-password-1'])
            ->assertStatus(202)->assertJsonMissing(['token']);
        $this->assertTrue($user->fresh()->hasTwoFactor());
    }
}
