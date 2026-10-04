<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DeviantartUser;
use App\Models\User;
use App\Utils\AccountHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class DeviantArtPkceTest extends TestCase
{
    use RefreshDatabase;

    private const DA_ID = '0f0e0d0c-0b0a-4000-8000-000000000042';

    private function challengeOf(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function testTheRedirectWorksWithoutASessionAndKeepsTheVerifierInACookie(): void
    {
        $response = $this->get('/users/oauth/signin/deviantart');

        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === AccountHelper::PKCE_COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull(collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie')), 'no session is started');
    }

    public function testTheCodeExchangeSendsTheVerifierFromTheCookie(): void
    {
        $user = User::factory()->create(['role' => Role::Member, 'name' => 'PkceUser']);
        (new DeviantartUser(['name' => 'PkceUser', 'avatar_url' => 'https://example.com/a.png']))->forceFill(['id' => self::DA_ID, 'user_id' => $user->id])->save();

        $response = $this->get('/users/oauth/signin/deviantart');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === AccountHelper::PKCE_COOKIE);

        // Socialite talks to the provider through its own Guzzle client, the manager hands out the same driver instance each time
        $history = [];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['userid' => self::DA_ID, 'username' => 'PkceUser', 'usericon' => 'https://example.com/a.png'])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        Socialite::driver('deviantart')->setHttpClient(new Client(['handler' => $stack]));

        $this->withUnencryptedCookie(AccountHelper::PKCE_COOKIE, $cookie->getValue())
            ->postJson('/users/oauth/signin/deviantart', ['code' => 'abc'])
            ->assertSuccessful();

        $this->assertNotEmpty($history, 'the token request was made');
        parse_str((string) $history[0]['request']->getBody(), $form);
        $verifier = $form['code_verifier'] ?? null;
        $this->assertNotEmpty($verifier);
        $this->assertSame($query['code_challenge'], $this->challengeOf($verifier));
    }
}
