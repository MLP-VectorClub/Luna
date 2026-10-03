<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\ImageProviderException;
use App\Models\User;
use App\Utils\DeviantArt;
use App\Utils\ImageProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TestFixturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.test_providers' => true]);
        foreach (['dabc123', 'dnothere'] as $id) {
            Cache::forget("deviation:fav.me:$id");
            Cache::forget(DeviantArt::CLUB_GALLERY_CACHE_PREFIX.$id);
        }
        // The Cloudflare ranges are cached by the proxy middleware, anything else asking the network is a bug
        Http::fake(fn($request) => str_contains($request->url(), 'cloudflare.com') ? Http::response('') : throw new \RuntimeException('Unexpected request to '.$request->url()));
    }

    public function testADeviationCanBeSeededAndIsThenResolvedWithoutTheNetwork(): void
    {
        $this->putJson('/test/deviations/dabc123', ['preview' => 'http://localhost/p.png', 'fullsize' => 'http://localhost/f.png', 'title' => 'Seeded', 'author' => 'Someone'])->assertCreated();

        $image = ImageProvider::resolve('https://fav.me/dabc123');

        $this->assertSame('http://localhost/p.png', $image->preview);
        $this->assertSame('http://localhost/f.png', $image->fullsize);
        $this->assertSame('Seeded', $image->title);
        $this->assertSame('Someone', $image->author);

        $this->deleteJson('/test/deviations/dabc123')->assertNoContent();
        $this->expectException(ImageProviderException::class);
        ImageProvider::resolve('https://fav.me/dabc123');
    }

    public function testAnUnknownDeviationIsNotFoundInsteadOfAskingDeviantArt(): void
    {
        $this->expectException(ImageProviderException::class);
        $this->expectExceptionMessageMatches('/could not be found/');

        ImageProvider::resolve('https://fav.me/dnothere');
    }

    public function testTheClubGalleryIsAMarkerPerDeviation(): void
    {
        $this->assertFalse(DeviantArt::isDeviationInClub('dabc123'));

        $this->putJson('/test/club-gallery/dabc123')->assertNoContent();
        $this->assertTrue(DeviantArt::isDeviationInClub('dabc123'));

        $this->deleteJson('/test/club-gallery/dabc123')->assertNoContent();
        $this->assertFalse(DeviantArt::isDeviationInClub('dabc123'));
    }

    public function testTheDeviationNeedsAPreview(): void
    {
        $this->putJson('/test/deviations/dabc123', [])->assertJsonValidationErrors('preview');
    }

    public function testASeededUserGetsACookieSession(): void
    {
        $user = User::factory()->create(['role' => Role::Member]);

        $response = $this->get("/test/session/{$user->id}?to=/show");

        $response->assertRedirect('/show');
        $response->assertCookie(config('session.cookie'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->getJson('/test/session/987654')->assertNotFound();
    }

    public function testNothingIsFakedWhenTheModeIsOff(): void
    {
        config(['app.test_providers' => false]);

        $this->assertFalse(DeviantArt::fakeProviders());
    }
}
