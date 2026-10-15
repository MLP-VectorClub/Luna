<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HoneypotTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('traps')]
    public function testATrapFlagsTheSessionAndRefusesIt(string $trap): void
    {
        $this->get("/honeypot/$trap")->assertForbidden();

        // The same session is refused everywhere from then on, the web group and the API both
        $this->get('/about/server-info')->assertForbidden();
        $this->withHeaders(['Origin' => config('app.frontend_url'), 'Referer' => config('app.frontend_url').'/'])->getJson('/config')->assertForbidden();
    }

    public static function traps(): array
    {
        return [['env'], ['wp-admin'], ['admin-php']];
    }

    public function testEveryMethodSetsTheFlag(): void
    {
        $this->post('/honeypot/env')->assertForbidden();
        $this->get('/about/server-info')->assertForbidden();
    }

    public function testOtherSessionsAreNotAffected(): void
    {
        $this->get('/honeypot/env')->assertForbidden();
        $this->flushSession();
        $this->app['session']->driver()->flush();

        $this->get('/about/server-info')->assertSuccessful();
        $this->getJson('/config')->assertOk();
    }

    public function testTheNameIsOnlyForTheLog(): void
    {
        $this->get('/honeypot/'.str_repeat('a', 41))->assertNotFound();
        $this->get('/honeypot/wp-login.php')->assertForbidden();
    }

    public function testTheFrontEndCanAskWhetherASessionIsFlagged(): void
    {
        $this->get('/internal/session-status')->assertNoContent();

        $this->get('/honeypot/env')->assertForbidden();
        $this->get('/internal/session-status')->assertForbidden();
    }
}
