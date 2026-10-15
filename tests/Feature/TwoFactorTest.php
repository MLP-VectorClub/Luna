<?php

namespace Tests\Feature;

use App\Models\RecoveryCode;
use App\Models\TwoFactor;
use App\Models\User;
use App\Utils\TwoFactorAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private function user(): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
    }

    /** A user with two-factor authentication on, and the secret of their app */
    private function userWithTwoFactor(): array
    {
        $user = $this->user();
        $secret = TwoFactorAuth::newSecret();
        TwoFactor::create(['user_id' => $user->id, 'secret' => $secret, 'confirmed_at' => now()]);
        $codes = TwoFactorAuth::replaceRecoveryCodes($user);

        return [$user, $secret, $codes];
    }

    private function code(string $secret, int $stepsAhead = 0): string
    {
        return (new Google2FA())->oathTotp($secret, (new Google2FA())->getTimestamp() + $stepsAhead);
    }

    private function challengeOf(User $user): string
    {
        return $this->postJson('/users/signin', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(202)
            ->json('twoFactor.challenge');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('x');
    }

    public function testSigninWithoutTwoFactorIsUnchanged(): void
    {
        $user = $this->user();

        $this->postJson('/users/signin', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function testPasswordAloneSignsNobodyInWhenTwoFactorIsOn(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->postJson('/users/signin', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(202)
            ->assertJsonPath('twoFactor.methods', ['totp', 'recovery'])
            ->assertJsonMissing(['token']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function testWrongPasswordStillFailsBeforeAnyChallenge(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->postJson('/users/signin', ['email' => $user->email, 'password' => 'not-the-password'])->assertUnauthorized();
    }

    public function testAValidCodeCompletesTheSignin(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $challenge = $this->challengeOf($user);

        $token = $this->postJson('/users/signin/two-factor', ['challenge' => $challenge, 'code' => $this->code($secret)])
            ->assertOk()
            ->assertJsonStructure(['token'])
            ->json('token');

        $this->withToken($token)->getJson('/users/me')->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function testTheStatefulSigninEndsInASessionNotAToken(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $origin = config('app.frontend_url');
        $headers = ['Origin' => $origin, 'Referer' => $origin . '/'];

        $challenge = $this->withHeaders($headers)->postJson('/users/signin', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(202)->json('twoFactor.challenge');
        $this->assertGuest('web');

        $this->withHeaders($headers)->postJson('/users/signin/two-factor', ['challenge' => $challenge, 'code' => $this->code($secret)])
            ->assertNoContent();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function testAWrongCodeIsRejectedAndSpendsTheChallengeAfterFiveTries(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $challenge = $this->challengeOf($user);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/users/signin/two-factor', ['challenge' => $challenge, 'code' => '000000'])
                ->assertUnauthorized()->assertJsonMissing(['expired' => true]);
        }
        $this->postJson('/users/signin/two-factor', ['challenge' => $challenge, 'code' => '000000'])
            ->assertUnauthorized()->assertJsonPath('expired', true);

        // Even the right code is no good any more
        $this->postJson('/users/signin/two-factor', ['challenge' => $challenge, 'code' => $this->code($secret)])
            ->assertUnauthorized()->assertJsonPath('expired', true);
    }

    public function testAChallengeWorksOnce(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $challenge = $this->challengeOf($user);

        $this->postJson('/users/signin/two-factor', ['challenge' => $challenge, 'code' => $this->code($secret)])->assertOk();
        $this->postJson('/users/signin/two-factor', ['challenge' => $challenge, 'code' => $this->code($secret, 1)])
            ->assertUnauthorized()->assertJsonPath('expired', true);
    }

    public function testAForgedOrOtherPurposeChallengeIsRejected(): void
    {
        [, $secret] = $this->userWithTwoFactor();

        $this->postJson('/users/signin/two-factor', ['challenge' => 'nonsense', 'code' => $this->code($secret)])
            ->assertUnauthorized()->assertJsonPath('expired', true);
        $this->postJson('/users/signin/two-factor', ['challenge' => \App\Utils\SignedChallenge::issue('other', ['user' => 1], 300), 'code' => $this->code($secret)])
            ->assertUnauthorized();
    }

    public function testAnExpiredChallengeIsRejected(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $challenge = $this->challengeOf($user);

        $this->travel(6)->minutes();

        $this->postJson('/users/signin/two-factor', ['challenge' => $challenge, 'code' => $this->code($secret)])
            ->assertUnauthorized()->assertJsonPath('expired', true);
    }

    public function testACodeIsGoodOnce(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $code = $this->code($secret);

        $this->postJson('/users/signin/two-factor', ['challenge' => $this->challengeOf($user), 'code' => $code])->assertOk();
        $this->postJson('/users/signin/two-factor', ['challenge' => $this->challengeOf($user), 'code' => $code])->assertUnauthorized();
    }

    public function testARecoveryCodeWorksOnce(): void
    {
        [$user,, $codes] = $this->userWithTwoFactor();

        $this->postJson('/users/signin/two-factor', ['challenge' => $this->challengeOf($user), 'recoveryCode' => strtoupper($codes[0])])
            ->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/users/signin/two-factor', ['challenge' => $this->challengeOf($user), 'recoveryCode' => $codes[0]])
            ->assertUnauthorized();
        $this->postJson('/users/signin/two-factor', ['challenge' => $this->challengeOf($user), 'recoveryCode' => $codes[1]])
            ->assertOk();
    }

    public function testACodeIsRequired(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->postJson('/users/signin/two-factor', ['challenge' => $this->challengeOf($user)])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
    }

    public function testManyWrongCodesLockTheAccountForAWhile(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/users/signin/two-factor', ['challenge' => $this->challengeOf($user), 'code' => '000000'])->assertUnauthorized();
        }
        $this->postJson('/users/signin/two-factor', ['challenge' => $this->challengeOf($user), 'code' => $this->code($secret)])
            ->assertStatus(429);
    }

    public function testAnUnconfirmedSetupDoesNotAskForACode(): void
    {
        $user = $this->user();
        TwoFactor::create(['user_id' => $user->id, 'secret' => TwoFactorAuth::newSecret()]);

        $this->postJson('/users/signin', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
    }

    public function testSetupConfirmAndRecoveryCodes(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->getJson('/users/me/two-factor')
            ->assertOk()->assertExactJson(['enabled' => false, 'recoveryCodesRemaining' => 0]);

        $start = $this->actingAs($user, 'sanctum')->postJson('/users/me/two-factor', ['currentPassword' => self::PASSWORD])
            ->assertOk()->assertJsonStructure(['secret', 'uri']);
        $this->assertStringStartsWith('otpauth://totp/', $start->json('uri'));

        // Not on until a code is shown
        $this->assertFalse($user->hasTwoFactor());
        $this->actingAs($user, 'sanctum')->postJson('/users/me/two-factor/confirm', ['code' => '000000'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $codes = $this->actingAs($user, 'sanctum')
            ->postJson('/users/me/two-factor/confirm', ['code' => $this->code($start->json('secret'))])
            ->assertOk()->json('recoveryCodes');
        $this->assertCount(10, $codes);
        $this->assertTrue($user->hasTwoFactor());
        $this->assertSame(10, TwoFactorAuth::remainingRecoveryCodes($user));

        // Codes are stored hashed
        $this->assertDatabaseMissing('luna_recovery_codes', ['code_hash' => $codes[0]]);
        // The secret is stored encrypted
        $this->assertNotSame($start->json('secret'), \DB::table('luna_two_factor')->value('secret'));

        $this->actingAs($user, 'sanctum')->postJson('/users/me/two-factor', ['currentPassword' => self::PASSWORD])->assertStatus(409);
        $this->actingAs($user, 'sanctum')->postJson('/users/me/two-factor/confirm', ['code' => '123456'])->assertStatus(409);
    }

    public function testChangesNeedThePassword(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->postJson('/users/me/two-factor', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['currentPassword']);
        $this->actingAs($user, 'sanctum')->postJson('/users/me/two-factor', ['currentPassword' => 'wrong-password'])
            ->assertUnprocessable()->assertJsonValidationErrors(['currentPassword']);

        [$user] = $this->userWithTwoFactor();
        $this->actingAs($user, 'sanctum')->deleteJson('/users/me/two-factor', [])->assertUnprocessable();
        $this->actingAs($user, 'sanctum')->postJson('/users/me/two-factor/recovery-codes', ['currentPassword' => 'wrong-password'])->assertUnprocessable();
        $this->assertTrue($user->hasTwoFactor());
    }

    public function testNewRecoveryCodesReplaceTheOldOnes(): void
    {
        [$user,, $old] = $this->userWithTwoFactor();

        $new = $this->actingAs($user, 'sanctum')
            ->postJson('/users/me/two-factor/recovery-codes', ['currentPassword' => self::PASSWORD])
            ->assertOk()->json('recoveryCodes');

        $this->assertCount(10, $new);
        $this->assertFalse(TwoFactorAuth::useRecoveryCode($user, $old[0]));
        $this->assertTrue(TwoFactorAuth::useRecoveryCode($user, $new[0]));
    }

    public function testDisablingEndsTheOtherSessionsAndTokens(): void
    {
        [$user] = $this->userWithTwoFactor();
        $user->createToken('other device');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/users/me/two-factor', ['currentPassword' => self::PASSWORD])
            ->assertNoContent();

        $this->assertFalse($user->hasTwoFactor());
        $this->assertSame(0, RecoveryCode::where('user_id', $user->id)->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/users/signin', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
    }

    public function testOauthOnlyAccountsConfirmWithACode(): void
    {
        $user = User::factory()->create(['password' => null]);
        $secret = TwoFactorAuth::newSecret();
        TwoFactor::create(['user_id' => $user->id, 'secret' => $secret, 'confirmed_at' => now()]);

        $this->actingAs($user, 'sanctum')->deleteJson('/users/me/two-factor', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->actingAs($user, 'sanctum')->deleteJson('/users/me/two-factor', ['code' => $this->code($secret)])
            ->assertNoContent();
    }

    public function testManagingNeedsSignIn(): void
    {
        $this->getJson('/users/me/two-factor')->assertUnauthorized();
        $this->postJson('/users/me/two-factor', [])->assertUnauthorized();
    }
}
