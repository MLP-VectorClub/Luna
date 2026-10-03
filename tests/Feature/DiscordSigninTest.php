<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DiscordMember;
use App\Models\User;
use App\Utils\AccountHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class DiscordSigninTest extends TestCase
{
    use RefreshDatabase;

    private function discordUser(): SocialiteUser
    {
        $user = (new SocialiteUser())->map(['id' => '72062699806130176', 'name' => 'Crazy', 'email' => 'crazy@example.com']);
        $user->setRaw(['username' => 'crazy', 'discriminator' => '0', 'avatar' => 'abc']);
        $user->accessTokenResponseBody = ['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600];

        return $user;
    }

    public function testAServerMemberWithoutAnAccountCannotSignIn(): void
    {
        (new DiscordMember(['username' => 'crazy', 'discriminator' => 0]))->forceFill(['id' => '72062699806130176', 'user_id' => null])->save();

        $this->expectException(NotFoundHttpException::class);
        AccountHelper::socialDiscord($this->discordUser(), false);
    }

    public function testAServerMemberGetsAnAccountWhenRegistering(): void
    {
        (new DiscordMember(['username' => 'crazy', 'discriminator' => 0]))->forceFill(['id' => '72062699806130176', 'user_id' => null])->save();

        $user = AccountHelper::socialDiscord($this->discordUser(), true);

        $this->assertSame('Crazy', $user->name);
        $this->assertSame($user->id, DiscordMember::find('72062699806130176')->user_id);
    }

    public function testABoundMemberSignsIn(): void
    {
        $bound = User::factory()->create(['role' => Role::Member]);
        (new DiscordMember(['username' => 'crazy', 'discriminator' => 0]))->forceFill(['id' => '72062699806130176', 'user_id' => $bound->id])->save();

        $this->assertSame($bound->id, AccountHelper::socialDiscord($this->discordUser(), false)->id);
    }
}
