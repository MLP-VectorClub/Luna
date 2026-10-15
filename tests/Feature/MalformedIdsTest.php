<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** An id that is no id (letters, or more digits than the database holds) is a missing page, not a server error */
class MalformedIdsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('paths')]
    public function testAMalformedIdIsNotFound(string $path): void
    {
        $this->getJson($path)->assertNotFound()->assertJsonPath('message', 'Not found');
    }

    public static function paths(): array
    {
        return [
            'letters' => ['/appearances/abc'],
            'letters, locate' => ['/appearances/abc/locate'],
            'letters, sprite' => ['/appearances/abc/sprite'],
            'letters, color groups' => ['/appearances/abc/color-groups'],
            'injection shaped' => ['/appearances/'.rawurlencode("1' OR '1'='1")],
            'too many digits, appearance' => ['/appearances/99999999999999999999'],
            'too many digits, user' => ['/users/99999999999999999999'],
            'too many digits, profile' => ['/users/99999999999999999999/profile'],
            'too many digits, show' => ['/show/99999999999999999999'],
            'too many digits, post' => ['/posts/99999999999999999999/deviation'],
        ];
    }

    public function testARealIdThatIsMissingStillSaysSo(): void
    {
        $this->getJson('/appearances/987654')->assertNotFound();
    }

    public function testTheNamedAppearanceRoutesStillWork(): void
    {
        $this->getJson('/appearances/pinned?guide=pony')->assertOk();
        $this->getJson('/appearances/autocomplete?guide=pony&q=tw')->assertOk();
    }

    public function testWritesWithAMalformedIdAreNotTurnedIntoNotFound(): void
    {
        // Only reads: for a write the value may come out of a body that should have been validated
        $this->postJson('/users/signin', ['email' => 'a@example.com', 'password' => 'x'])->assertStatus(401);
    }
}
