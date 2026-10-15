<?php

namespace Tests\Feature;

use App\Models\User;
use App\Rules\UniqueEmail;
use App\Utils\EmailVault;
use App\Utils\UserEmailEncryption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class EmailEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private function stored(User $user): object
    {
        return DB::table('users')->where('id', $user->id)->first(['email', 'email_hash']);
    }

    public function testTheDatabaseHoldsTheAddressEncryptedAndTheModelReadsItPlain(): void
    {
        $user = User::factory()->create(['email' => 'Pony.Fan@Example.com']);
        $row = $this->stored($user);

        $this->assertNotSame('Pony.Fan@Example.com', $row->email);
        $this->assertStringNotContainsString('example.com', $row->email);
        $this->assertStringNotContainsString('Pony', $row->email);
        $this->assertTrue(EmailVault::isEncrypted($row->email));
        $this->assertSame('Pony.Fan@Example.com', $user->fresh()->email);
        $this->assertSame(EmailVault::hash('pony.fan@example.com'), $row->email_hash);
        $this->assertSame(64, strlen($row->email_hash));
    }

    public function testTheCiphertextDiffersEveryTimeButTheHashDoesNot(): void
    {
        $a = User::factory()->create(['email' => 'same@example.com']);
        $this->assertNotSame($this->stored($a)->email, EmailVault::encrypt('same@example.com'));
        $this->assertSame(EmailVault::hash('same@example.com'), EmailVault::hash('SAME@example.com '));
    }

    public function testUsersAreFoundByAddressWhateverTheCase(): void
    {
        $user = User::factory()->create(['email' => 'find.me@example.com']);
        User::factory()->create(['email' => 'other@example.com']);

        $this->assertSame($user->id, User::whereEmail('FIND.ME@example.com')->value('id'));
        $this->assertNull(User::whereEmail('nobody@example.com')->first());
        // No address finds nobody, not everybody who has none
        User::factory()->create(['email' => null]);
        $this->assertFalse(User::whereEmail(null)->exists());
        $this->assertFalse(User::whereEmail('')->exists());
    }

    public function testChangingTheAddressChangesTheHashToo(): void
    {
        $user = User::factory()->create(['email' => 'first@example.com']);
        $user->forceFill(['email' => 'second@example.com'])->save();

        $this->assertSame(EmailVault::hash('second@example.com'), $this->stored($user)->email_hash);
        $this->assertFalse(User::whereEmail('first@example.com')->exists());
        $this->assertTrue(User::whereEmail('second@example.com')->exists());

        $user->forceFill(['email' => null])->save();
        $row = $this->stored($user);
        $this->assertNull($row->email);
        $this->assertNull($row->email_hash);
    }

    public function testTheHashIsNotInTheArrayOfAUserAndTheAddressStaysProtected(): void
    {
        $user = User::factory()->create(['email' => 'private@example.com']);

        $this->assertArrayNotHasKey('email_hash', $user->toArray());
        $this->assertArrayNotHasKey('email', $user->toArray());
        $this->assertSame('private@example.com', $user->toArrayWithProtected()['email']);
    }

    public function testTwoUsersCannotHaveTheSameAddressApartFromItsCase(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->assertFalse(Validator::make(['email' => 'TAKEN@example.com'], ['email' => [new UniqueEmail()]])->passes());
        $this->assertTrue(Validator::make(['email' => 'free@example.com'], ['email' => [new UniqueEmail()]])->passes());
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        User::factory()->create(['email' => 'Taken@Example.com']);
    }

    public function testAnAddressCanBeChangedToItself(): void
    {
        $user = User::factory()->create(['email' => 'mine@example.com']);

        $this->assertTrue(Validator::make(['email' => 'mine@example.com'], ['email' => [new UniqueEmail($user->id)]])->passes());
    }

    public function testAddressesWrittenPlainAreReadAsTheyAreAndEncryptedByTheCommand(): void
    {
        // What the old site (or an older copy of the table) leaves: a plain address, no hash
        $id = DB::table('users')->insertGetId(['name' => 'Plain', 'email' => 'plain@example.com', 'role' => 'user']);
        $user = User::findOrFail($id);
        $this->assertSame('plain@example.com', $user->email);
        $this->assertFalse(User::whereEmail('plain@example.com')->exists());

        $this->artisan('users:encrypt-emails --dry-run')->expectsOutputToContain('1 address(es) to encrypt')->assertSuccessful();
        $this->assertSame('plain@example.com', $this->stored($user)->email);

        $this->artisan('users:encrypt-emails')->expectsOutputToContain('1 address(es) encrypted')->assertSuccessful();
        $row = $this->stored($user);
        $this->assertTrue(EmailVault::isEncrypted($row->email));
        $this->assertSame('plain@example.com', $user->fresh()->email);
        $this->assertTrue(User::whereEmail('PLAIN@example.com')->exists());

        // And again: nothing is left to do
        $this->artisan('users:encrypt-emails')->expectsOutputToContain('0 address(es) encrypted, 0 hash(es) built')->assertSuccessful();
    }

    public function testTheCommandReportsTwoAccountsWithTheSameAddress(): void
    {
        DB::table('users')->insert([
            ['name' => 'First', 'email' => 'dupe@example.com', 'role' => 'user'],
            ['name' => 'Second', 'email' => 'DUPE@example.com', 'role' => 'user'],
        ]);

        $result = UserEmailEncryption::run();

        $this->assertSame(2, $result['encrypted']);
        $this->assertCount(1, $result['conflicts']);
        // The second one is encrypted all the same, it is only not findable by its address
        $this->assertSame(0, DB::table('users')->whereNotNull('email')->get()->filter(fn($r) => !EmailVault::isEncrypted($r->email))->count());
    }

    public function testRehashingEncryptsEverythingAgainAndKeepsTheAddresses(): void
    {
        $user = User::factory()->create(['email' => 'again@example.com']);
        $before = $this->stored($user)->email;

        $result = UserEmailEncryption::run(rehash: true);

        $this->assertSame(1, $result['encrypted']);
        $this->assertNotSame($before, $this->stored($user)->email);
        $this->assertSame('again@example.com', $user->fresh()->email);
    }

    public function testAValueThatCannotBeDecryptedIsNoAddressAtAll(): void
    {
        $other = new \Illuminate\Encryption\Encrypter(str_repeat('z', 32), 'AES-256-CBC');
        $id = DB::table('users')->insertGetId(['name' => 'Elsewhere', 'email' => $other->encryptString('x@example.com'), 'role' => 'user']);

        $this->assertNull(User::findOrFail($id)->email);
        $this->assertSame(0, UserEmailEncryption::run()['encrypted']);
    }

    public function testTheHashKeyCanBeSetApartFromTheAppKey(): void
    {
        $withAppKey = EmailVault::hash('a@example.com');
        config(['app.email_hash_key' => 'a different key']);

        $this->assertNotSame($withAppKey, EmailVault::hash('a@example.com'));
    }
}
