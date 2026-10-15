<?php

namespace Tests\Feature;

use App\Models\Passkey;
use App\Models\TwoFactor;
use App\Models\User;
use App\Utils\TwoFactorAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;

class PasskeyTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function user(): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
    }

    private function authenticator(): FakeAuthenticator
    {
        return new FakeAuthenticator(config('webauthn.origins')[0], config('webauthn.rp_id'));
    }

    /** Adds a passkey through the API the way a browser would */
    private function addPasskey(User $user, FakeAuthenticator $authenticator, string $name = 'Laptop'): Passkey
    {
        $start = $this->actingAs($user, 'sanctum')
            ->postJson('/users/me/passkeys/options', ['currentPassword' => self::PASSWORD])
            ->assertOk()->assertJsonStructure(['options' => ['challenge', 'rp', 'user'], 'challenge']);

        $this->actingAs($user, 'sanctum')->postJson('/users/me/passkeys', [
            'challenge' => $start->json('challenge'),
            'credential' => $authenticator->register($start->json('options')),
            'name' => $name,
        ])->assertCreated()->assertJsonPath('name', $name);

        return Passkey::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    public function testRegisterListRenameAndRemove(): void
    {
        $user = $this->user();
        $passkey = $this->addPasskey($user, $this->authenticator(), 'Laptop');

        $this->actingAs($user, 'sanctum')->getJson('/users/me/passkeys')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Laptop')->assertJsonPath('0.lastUsedAt', null)
            ->assertJsonMissingPath('0.record');

        $this->actingAs($user, 'sanctum')->patchJson("/users/me/passkeys/{$passkey->id}", ['name' => 'Phone'])
            ->assertOk()->assertJsonPath('name', 'Phone');

        $this->actingAs($user, 'sanctum')->deleteJson("/users/me/passkeys/{$passkey->id}", [])->assertUnprocessable();
        $this->actingAs($user, 'sanctum')->deleteJson("/users/me/passkeys/{$passkey->id}", ['currentPassword' => self::PASSWORD])->assertNoContent();
        $this->assertDatabaseCount('luna_passkeys', 0);
    }

    public function testAddingAPasskeyNeedsThePassword(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->postJson('/users/me/passkeys/options', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['currentPassword']);
    }

    public function testSomebodyElsesPasskeyCannotBeTouched(): void
    {
        $owner = $this->user();
        $passkey = $this->addPasskey($owner, $this->authenticator());
        $other = $this->user();

        $this->actingAs($other, 'sanctum')->patchJson("/users/me/passkeys/{$passkey->id}", ['name' => 'Mine'])->assertNotFound();
        $this->actingAs($other, 'sanctum')->deleteJson("/users/me/passkeys/{$passkey->id}", ['currentPassword' => self::PASSWORD])->assertNotFound();
    }

    public function testRegistrationRejectsAnAnswerForOtherOptionsAndTheWrongOrigin(): void
    {
        $user = $this->user();
        $start = $this->actingAs($user, 'sanctum')
            ->postJson('/users/me/passkeys/options', ['currentPassword' => self::PASSWORD])->assertOk();

        $evil = new FakeAuthenticator('https://evil.example', config('webauthn.rp_id'));
        $this->actingAs($user, 'sanctum')->postJson('/users/me/passkeys', [
            'challenge' => $start->json('challenge'),
            'credential' => $evil->register($start->json('options')),
        ])->assertUnprocessable();

        // The options were used up by the attempt
        $this->actingAs($user, 'sanctum')->postJson('/users/me/passkeys', [
            'challenge' => $start->json('challenge'),
            'credential' => $this->authenticator()->register($start->json('options')),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('luna_passkeys', 0);
    }

    public function testRegistrationOptionsOfAnotherUserAreRejected(): void
    {
        $user = $this->user();
        $start = $this->actingAs($user, 'sanctum')
            ->postJson('/users/me/passkeys/options', ['currentPassword' => self::PASSWORD])->assertOk();
        $other = $this->user();

        $this->actingAs($other, 'sanctum')->postJson('/users/me/passkeys', [
            'challenge' => $start->json('challenge'),
            'credential' => $this->authenticator()->register($start->json('options')),
        ])->assertUnprocessable();
    }

    public function testSigninWithAPasskeyAlone(): void
    {
        $user = $this->user();
        $authenticator = $this->authenticator();
        $this->addPasskey($user, $authenticator);
        $this->app['auth']->forgetGuards();

        $start = $this->postJson('/users/signin/passkey/options')->assertOk()
            ->assertJsonPath('options.rpId', config('webauthn.rp_id'))->assertJsonPath('options.allowCredentials', [])
            ->assertJsonStructure(['options' => ['challenge'], 'challenge']);

        $token = $this->postJson('/users/signin/passkey', [
            'challenge' => $start->json('challenge'),
            'credential' => $authenticator->sign($start->json('options'), (string)$user->id),
        ])->assertOk()->assertJsonStructure(['token'])->json('token');

        $this->withToken($token)->getJson('/users/me')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertNotNull(Passkey::first()->last_used_at);
    }

    public function testTheOptionsOfASigninLeaveNothingOnTheServer(): void
    {
        $origin = config('app.frontend_url');

        $this->withHeaders(['Origin' => $origin, 'Referer' => $origin . '/'])->postJson('/users/signin/passkey/options')->assertOk();

        $this->assertDatabaseCount('luna_sessions', 0);
    }

    public function testTheStatefulSigninEndsInASession(): void
    {
        $user = $this->user();
        $authenticator = $this->authenticator();
        $this->addPasskey($user, $authenticator);
        // actingAs made sanctum the default guard, a browser's request is handled by the web one
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $origin = config('app.frontend_url');
        $headers = ['Origin' => $origin, 'Referer' => $origin . '/'];

        $start = $this->withHeaders($headers)->postJson('/users/signin/passkey/options')->assertOk();
        $this->withHeaders($headers)->postJson('/users/signin/passkey', [
            'challenge' => $start->json('challenge'),
            'credential' => $authenticator->sign($start->json('options'), (string)$user->id),
        ])->assertNoContent();

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function testTheOptionsWorkOnce(): void
    {
        $user = $this->user();
        $authenticator = $this->authenticator();
        $this->addPasskey($user, $authenticator);
        $this->app['auth']->forgetGuards();

        $start = $this->postJson('/users/signin/passkey/options')->assertOk();
        $credential = $authenticator->sign($start->json('options'), (string)$user->id);

        $this->postJson('/users/signin/passkey', ['challenge' => $start->json('challenge'), 'credential' => $credential])->assertOk();
        $this->postJson('/users/signin/passkey', ['challenge' => $start->json('challenge'), 'credential' => $credential])
            ->assertUnauthorized()->assertJsonPath('expired', true);
    }

    public function testAnAnswerToOtherOptionsIsRejected(): void
    {
        $user = $this->user();
        $authenticator = $this->authenticator();
        $this->addPasskey($user, $authenticator);
        $this->app['auth']->forgetGuards();

        $first = $this->postJson('/users/signin/passkey/options')->assertOk();
        $second = $this->postJson('/users/signin/passkey/options')->assertOk();

        $this->postJson('/users/signin/passkey', [
            'challenge' => $first->json('challenge'),
            'credential' => $authenticator->sign($second->json('options'), (string)$user->id),
        ])->assertUnauthorized();
    }

    public function testAnUnknownPasskeyIsRejected(): void
    {
        $start = $this->postJson('/users/signin/passkey/options')->assertOk();

        $this->postJson('/users/signin/passkey', [
            'challenge' => $start->json('challenge'),
            'credential' => $this->authenticator()->sign($start->json('options'), '1'),
        ])->assertUnauthorized();
    }

    public function testACounterThatGoesBackwardsIsRejected(): void
    {
        $user = $this->user();
        $authenticator = $this->authenticator();
        $this->addPasskey($user, $authenticator);
        $this->app['auth']->forgetGuards();

        $start = $this->postJson('/users/signin/passkey/options')->assertOk();
        $this->postJson('/users/signin/passkey', [
            'challenge' => $start->json('challenge'),
            'credential' => $authenticator->sign($start->json('options'), (string)$user->id, 5),
        ])->assertOk();

        // A clone of the key would come with a counter that is not ahead
        $start = $this->postJson('/users/signin/passkey/options')->assertOk();
        $this->postJson('/users/signin/passkey', [
            'challenge' => $start->json('challenge'),
            'credential' => $authenticator->sign($start->json('options'), (string)$user->id, 3),
        ])->assertUnauthorized();
    }

    public function testAPasskeyIsTheSecondFactorToo(): void
    {
        $user = $this->user();
        $authenticator = $this->authenticator();
        $this->addPasskey($user, $authenticator);
        TwoFactor::create(['user_id' => $user->id, 'secret' => TwoFactorAuth::newSecret(), 'confirmed_at' => now()]);
        $this->app['auth']->forgetGuards();

        $challenge = $this->postJson('/users/signin', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(202)->assertJsonPath('twoFactor.methods', ['totp', 'recovery', 'passkey'])
            ->json('twoFactor.challenge');

        $options = $this->postJson('/users/signin/two-factor/passkey/options', ['challenge' => $challenge])
            ->assertOk()->assertJsonCount(1, 'options.allowCredentials');

        $this->postJson('/users/signin/two-factor/passkey', [
            'challenge' => $challenge,
            'passkeyChallenge' => $options->json('challenge'),
            'credential' => $authenticator->sign($options->json('options'), (string)$user->id),
        ])->assertOk()->assertJsonStructure(['token']);

        // The challenge is used up
        $this->postJson('/users/signin/two-factor/passkey', [
            'challenge' => $challenge,
            'passkeyChallenge' => $options->json('challenge'),
            'credential' => $authenticator->sign($options->json('options'), (string)$user->id),
        ])->assertUnauthorized();
    }

    public function testSomebodyElsesPasskeyIsNotASecondFactor(): void
    {
        $user = $this->user();
        $this->addPasskey($user, $this->authenticator());
        TwoFactor::create(['user_id' => $user->id, 'secret' => TwoFactorAuth::newSecret(), 'confirmed_at' => now()]);

        $other = $this->user();
        $otherKey = $this->authenticator();
        $this->addPasskey($other, $otherKey);
        $this->app['auth']->forgetGuards();

        $challenge = $this->postJson('/users/signin', ['email' => $user->email, 'password' => self::PASSWORD])->json('twoFactor.challenge');
        $options = $this->postJson('/users/signin/two-factor/passkey/options', ['challenge' => $challenge])->assertOk();

        $this->postJson('/users/signin/two-factor/passkey', [
            'challenge' => $challenge,
            'passkeyChallenge' => $options->json('challenge'),
            'credential' => $otherKey->sign($options->json('options'), (string)$other->id),
        ])->assertUnauthorized();
    }

    public function testAnAccountWithoutPasskeysGetsNoPasskeyOptionsAsSecondFactor(): void
    {
        $user = $this->user();
        TwoFactor::create(['user_id' => $user->id, 'secret' => TwoFactorAuth::newSecret(), 'confirmed_at' => now()]);

        $challenge = $this->postJson('/users/signin', ['email' => $user->email, 'password' => self::PASSWORD])->json('twoFactor.challenge');

        $this->postJson('/users/signin/two-factor/passkey/options', ['challenge' => $challenge])->assertUnauthorized();
    }

    public function testRemovingAPasskeyEndsTheOtherTokens(): void
    {
        $user = $this->user();
        $passkey = $this->addPasskey($user, $this->authenticator());
        $user->createToken('other device');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/users/me/passkeys/{$passkey->id}", ['currentPassword' => self::PASSWORD])->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function testPasskeysAreDeletedWithTheUser(): void
    {
        $user = $this->user();
        $this->addPasskey($user, $this->authenticator());

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertDatabaseCount('luna_passkeys', 0);
    }
}
