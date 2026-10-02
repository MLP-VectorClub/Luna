<?php

namespace Tests\Feature;

use App\Console\Commands\GdprAnonymizeLoggedIps;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GdprCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function testOldIpAddressesAreReplaced(): void
    {
        $old = DB::table('logs')->insertGetId(['entry_type' => 'appearances', 'initiator' => null, 'ip' => '203.0.113.9', 'created_at' => now()->subMonths(4), 'data' => null]);
        $recent = DB::table('logs')->insertGetId(['entry_type' => 'appearances', 'initiator' => null, 'ip' => '203.0.113.10', 'created_at' => now()->subMonths(2), 'data' => null]);
        DB::table('failed_auth_attempts')->insert([['ip' => '203.0.113.9', 'created_at' => now()->subMonths(4)], ['ip' => '203.0.113.10', 'created_at' => now()->subDay()]]);

        $this->artisan('gdpr:anonymize-logged-ips')->expectsOutput('1 log entry updated')->expectsOutput('1 failed auth attempt deleted')->assertSuccessful();

        $this->assertSame(GdprAnonymizeLoggedIps::IP_PLACEHOLDER, DB::table('logs')->where('id', $old)->value('ip'));
        $this->assertSame('203.0.113.10', DB::table('logs')->where('id', $recent)->value('ip'));
        $this->assertSame(1, DB::table('failed_auth_attempts')->count());
    }

    public function testOldEmailVerificationsAreDeleted(): void
    {
        $user = User::factory()->create();
        DB::table('email_verifications')->insert([
            ['user_id' => $user->id, 'email' => 'a@example.com', 'hash' => 'old', 'created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25)],
            ['user_id' => $user->id, 'email' => 'b@example.com', 'hash' => 'new', 'created_at' => now()->subHours(1), 'updated_at' => now()->subHours(1)],
        ]);

        $this->artisan('gdpr:prune-email-verifications')->expectsOutput('1 verification entry deleted')->assertSuccessful();

        $this->assertSame(['new'], DB::table('email_verifications')->pluck('hash')->all());
    }
}
