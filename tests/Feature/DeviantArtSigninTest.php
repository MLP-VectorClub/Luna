<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DeviantartUser;
use App\Models\PreviousUsername;
use App\Models\User;
use App\Utils\AccountHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class DeviantArtSigninTest extends TestCase
{
    use RefreshDatabase;

    private const DA_ID = '0f0e0d0c-0b0a-4000-8000-000000000042';

    private function daUser(string $nickname): SocialiteUser
    {
        $user = (new SocialiteUser())->map(['id' => self::DA_ID, 'nickname' => $nickname, 'avatar' => 'https://example.com/a.png']);
        $user->accessTokenResponseBody = ['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600];

        return $user;
    }

    private function existingUser(string $name, string $da_name): User
    {
        $user = User::factory()->create(['role' => Role::Member, 'name' => $name]);
        (new DeviantartUser(['name' => $da_name, 'avatar_url' => 'https://example.com/old.png']))->forceFill(['id' => self::DA_ID, 'user_id' => $user->id])->save();

        return $user;
    }

    public function testSigningInAfterARenameUpdatesTheNameAndKeepsTheOldOne(): void
    {
        $user = $this->existingUser('OldName', 'OldName');

        $signed_in = AccountHelper::socialDeviantart($this->daUser('NewName'), false);

        $this->assertSame($user->id, $signed_in->id);
        $this->assertSame('NewName', $user->fresh()->name);
        $this->assertSame('NewName', DeviantartUser::find(self::DA_ID)->name);
        $this->assertSame(['OldName'], PreviousUsername::where('user_id', self::DA_ID)->pluck('username')->all());
    }

    public function testSigningInWithoutARenameChangesNothing(): void
    {
        $user = $this->existingUser('SameName', 'SameName');

        AccountHelper::socialDeviantart($this->daUser('SameName'), false);
        AccountHelper::socialDeviantart($this->daUser('samename'), false);

        $this->assertSame('SameName', $user->fresh()->name);
        $this->assertSame(0, PreviousUsername::count());
    }

    public function testBothTheAccountNameAndTheLinkedNameAreRemembered(): void
    {
        $user = $this->existingUser('SiteName', 'DaName');

        AccountHelper::socialDeviantart($this->daUser('Renamed'), false);

        $this->assertSame('Renamed', $user->fresh()->name);
        $this->assertEqualsCanonicalizing(['SiteName', 'DaName'], PreviousUsername::pluck('username')->all());
    }

    public function testANameThatAnotherAccountHoldsIsNotTaken(): void
    {
        $user = $this->existingUser('Mine', 'Mine');
        User::factory()->create(['name' => 'Taken']);

        AccountHelper::socialDeviantart($this->daUser('Taken'), false);

        $this->assertSame('Mine', $user->fresh()->name);
        $this->assertSame('Taken', DeviantartUser::find(self::DA_ID)->name);
        $this->assertSame(['Mine'], PreviousUsername::pluck('username')->all());
    }

    public function testRegisteringCreatesNoHistory(): void
    {
        $created = AccountHelper::socialDeviantart($this->daUser('Fresh'), true);

        $this->assertSame('Fresh', $created->name);
        $this->assertSame(0, PreviousUsername::count());
    }

    public function testASignInWithoutALocalAccountAnswersWithAReason(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $this->expectExceptionMessage('Could not find local account for user');

        AccountHelper::socialDeviantart($this->daUser('Nobody'), false);
    }

    public function testTheReasonOfANotFoundErrorIsKept(): void
    {
        \Illuminate\Support\Facades\Route::middleware('api')->get('/_gone', fn() => abort(404, 'Could not find local account for user'));
        \Illuminate\Support\Facades\Route::middleware('api')->get('/_missing', fn() => throw new \Illuminate\Database\Eloquent\ModelNotFoundException());

        $this->getJson('/_gone')->assertNotFound()->assertExactJson(['message' => 'Could not find local account for user']);
        $this->getJson('/_missing')->assertNotFound()->assertExactJson(['message' => 'Not found']);
        $this->getJson('/no/such/route')->assertNotFound()->assertExactJson(['message' => 'Not found']);
    }
}
