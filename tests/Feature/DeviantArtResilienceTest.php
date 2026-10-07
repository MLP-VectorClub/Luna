<?php

namespace Tests\Feature;

use App\Exceptions\ImageProviderException;
use App\Jobs\RefreshDeviation;
use App\Utils\DeviantArt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class DeviantArtResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('deviantart-oembed');
    }

    private function assertOembedRequests(int $expected): void
    {
        $this->assertCount($expected, Http::recorded(fn ($request) => str_contains($request->url(), 'backend.deviantart.com/oembed')));
    }

    private function oembed(): array
    {
        return ['type' => 'photo', 'url' => 'https://img.example/f.png', 'thumbnail_url' => 'http://img.example/t.png', 'title' => 'Title', 'author_name' => 'Author'];
    }

    public function testASyncQueueFetchesOnceAndCaches(): void
    {
        Http::fake(['fav.me/*' => Http::response('', 301, ['Location' => 'https://www.deviantart.com/a/art/Title-123']), 'backend.deviantart.com/*' => Http::response($this->oembed())]);

        $image = DeviantArt::lookup('dabc001');
        $this->assertSame('Title', $image->title);
        DeviantArt::lookup('dabc001');
        $this->assertOembedRequests(1);
    }

    public function testAnAsyncQueueAnswersPendingAndTheJobFillsTheCache(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();

        $this->assertSame(DeviantArt::PENDING, DeviantArt::lookup('dabc002'));
        Bus::assertDispatched(RefreshDeviation::class, fn (RefreshDeviation $job) => $job->id === 'dabc002');

        Http::fake(['fav.me/*' => Http::response('', 301, ['Location' => 'https://www.deviantart.com/a/art/Title-123']), 'backend.deviantart.com/*' => Http::response($this->oembed())]);
        (new RefreshDeviation('dabc002'))->handle();
        $this->assertSame('Title', DeviantArt::lookup('dabc002')->title);
    }

    public function testRepeatedDenialsPauseAllRequestsToDeviantArt(): void
    {
        Http::fake(['fav.me/*' => Http::response('', 301, ['Location' => 'https://www.deviantart.com/a/art/Title-123']), 'backend.deviantart.com/*' => Http::response('', 403)]);

        foreach (['d1', 'd2', 'd3'] as $id) {
            try {
                DeviantArt::submission($id);
                $this->fail('Expected a denial');
            } catch (ImageProviderException) {
            }
        }
        $this->assertGreaterThan(0, DeviantArt::blockedFor());
        $this->assertOembedRequests(3);

        // Neither new submissions nor the denied ones reach DeviantArt while paused
        foreach (['d4', 'd1'] as $id) {
            try {
                DeviantArt::submission($id);
            } catch (ImageProviderException) {
            }
        }
        $this->assertOembedRequests(3);
    }

    public function testASingleDeniedSubmissionIsNotAskedAgainRightAway(): void
    {
        Http::fake(['fav.me/*' => Http::response('', 301, ['Location' => 'https://www.deviantart.com/a/art/Title-123']), 'backend.deviantart.com/*' => Http::response('', 403)]);

        for ($i = 0; $i < 3; $i++) {
            try {
                DeviantArt::submission('dsolo01');
            } catch (ImageProviderException) {
            }
        }
        $this->assertOembedRequests(1);
        $this->assertSame(0, DeviantArt::blockedFor());
    }

    public function testRateLimitingHonoursRetryAfter(): void
    {
        Http::fake(['fav.me/*' => Http::response('', 301, ['Location' => 'https://www.deviantart.com/a/art/Title-123']), 'backend.deviantart.com/*' => Http::response('', 429, ['Retry-After' => '900'])]);
        try {
            DeviantArt::submission('dlim001');
        } catch (ImageProviderException) {
        }
        $this->assertGreaterThanOrEqual(899, DeviantArt::blockedFor());

    }

    public function testMissingSubmissionsAreRemembered(): void
    {
        Http::fake(['fav.me/*' => Http::response('', 301, ['Location' => 'https://www.deviantart.com/a/art/Title-123']), 'backend.deviantart.com/*' => Http::response('', 404)]);
        $this->assertNull(DeviantArt::submission('dgone01'));
        $this->assertNull(DeviantArt::submission('dgone01'));
        $this->assertOembedRequests(1);
    }

    public function testTheJobWaitsOutAPauseAndKeepsStaleDetailsWhenARefreshFails(): void
    {
        Cache::put('deviation:fav.me:dold001', ['provider' => 'fav.me', 'id' => 'dold001', 'preview' => 'https://img.example/t.png', 'fullsize' => null, 'title' => 'Old', 'author' => null, 'type' => null], 60);
        Http::fake(['fav.me/*' => Http::response('', 301, ['Location' => 'https://www.deviantart.com/a/art/Title-123']), 'backend.deviantart.com/*' => Http::response('', 500)]);

        $job = new RefreshDeviation('dold001');
        $job->handle();
        $this->assertOembedRequests(1);
        $this->assertSame('Old', DeviantArt::cachedSubmission('dold001')->title);

        DeviantArt::block();
        $job->handle();
        $this->assertOembedRequests(1);
    }

    public function testFavMeLinksAreResolvedOverHttpBeforeAskingOembed(): void
    {
        Http::fake([
            'http://fav.me/dres001' => Http::response('', 301, ['Location' => 'https://www.deviantart.com/someone/art/Some-Title-123']),
            'backend.deviantart.com/*' => Http::response($this->oembed()),
        ]);

        $this->assertSame('Title', DeviantArt::submission('dres001')->title);
        Http::assertSent(fn ($request) => $request->url() === 'http://fav.me/dres001');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'backend.deviantart.com/oembed') && $request['url'] === 'https://www.deviantart.com/someone/art/Some-Title-123');

        // The resolved address is remembered
        Cache::forget('deviation:fav.me:dres001');
        DeviantArt::submission('dres001', 'fav.me', true);
        $this->assertOembedRequests(2);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->url() === 'http://fav.me/dres001'));
    }

    public function testDeviantArtHostsGoThroughTheProxyAndOthersDoNot(): void
    {
        config(['services.deviantart.proxy' => 'socks5h://127.0.0.1:40000']);

        $this->assertSame('socks5h://127.0.0.1:40000', DeviantArt::httpFor('https://images-wixmp-abc.wixmp.com/f/a.png')->getOptions()['proxy']);
        $this->assertSame('socks5h://127.0.0.1:40000', DeviantArt::http()->getOptions()['proxy']);
        $this->assertArrayNotHasKey('proxy', DeviantArt::httpFor('https://derpicdn.net/img/a.png')->getOptions());

        config(['services.deviantart.proxy' => null]);
        $this->assertArrayNotHasKey('proxy', DeviantArt::http()->getOptions());
    }
}
