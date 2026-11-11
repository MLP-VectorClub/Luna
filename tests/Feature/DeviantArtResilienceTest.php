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

        foreach (['d1', 'd2'] as $id) {
            try {
                DeviantArt::submission($id);
                $this->fail('Expected a denial');
            } catch (ImageProviderException) {
            }
        }
        $this->assertGreaterThanOrEqual(900, DeviantArt::blockedFor());
        $this->assertOembedRequests(2);

        // Neither new submissions nor the denied ones reach DeviantArt while paused
        foreach (['d4', 'd1'] as $id) {
            try {
                DeviantArt::submission($id);
            } catch (ImageProviderException) {
            }
        }
        $this->assertOembedRequests(2);
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

    private const UUID = '8B0358A6-0087-4855-C184-2B078E8D8A06';

    private function fakeApi(array $overrides = [], array $stubs = []): void
    {
        config(['services.deviantart.use_api' => true, 'services.deviantart.client_id' => 'id', 'services.deviantart.client_secret' => 'secret']);
        Http::fake($stubs + [
            'www.deviantart.com/deviation/*' => Http::response('<meta property="da:appurl" content="DeviantArt://deviation/'.self::UUID.'">'),
            'www.deviantart.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'www.deviantart.com/api/v1/oauth2/deviation/*' => Http::response($overrides + [
                'deviationid' => self::UUID,
                'title' => 'Future Silverstream',
                'author' => ['username' => 'CloudyGlow'],
                'content' => ['src' => 'https://images-wixmp.wixmp.com/f/x/a.png/v1/fill/w_1280,h_1333/a-fullview.png?token=t', 'width' => 1280, 'height' => 1333],
                'thumbs' => [['src' => 'https://t.example/150.png', 'width' => 150], ['src' => 'https://t.example/300.png', 'width' => 300], ['src' => 'https://t.example/400.png', 'width' => 400]],
                'preview' => ['src' => 'https://t.example/pre.png'],
            ]),
            'backend.deviantart.com/*' => Http::response($this->oembed()),
        ]);
    }

    public function testTheApiAnswersWithoutOembedAndTheIdIsKept(): void
    {
        $this->fakeApi();

        $image = DeviantArt::submission('dabc010');
        $this->assertSame('Future Silverstream', $image->title);
        $this->assertSame('CloudyGlow', $image->author);
        $this->assertSame('png', $image->type);
        $this->assertSame('https://t.example/300.png', $image->preview);
        $this->assertStringContainsString('-fullview.png', $image->fullsize);
        $this->assertOembedRequests(0);
        $this->assertCount(0, Http::recorded(fn ($request) => str_contains($request->url(), 'fav.me')));

        // The refresh goes straight to the API: no page, one token for both
        DeviantArt::submission('dabc010', 'fav.me', true);
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'www.deviantart.com/deviation/')));
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'oauth2/token')));
        $this->assertCount(2, Http::recorded(fn ($request) => str_contains($request->url(), 'api/v1/oauth2/deviation/')));
        $this->assertSame('MLP-VectorClub-Luna/1.0 (+https://mlpvector.club)', Http::recorded()[0][0]->header('User-Agent')[0]);
    }

    public function testSubmissionsWithoutAFileFallBackToOembed(): void
    {
        $this->fakeApi(['content' => null]);

        $this->assertSame('Title', DeviantArt::submission('dabc011')->title);
        $this->assertOembedRequests(1);
    }

    public function testAnApiRateLimitPausesAndHonoursRetryAfter(): void
    {
        $this->fakeApi([], ['www.deviantart.com/api/v1/oauth2/deviation/*' => Http::response('', 429, ['Retry-After' => '900'])]);

        try {
            DeviantArt::submission('dabc012');
            $this->fail('Expected a pause');
        } catch (ImageProviderException) {
        }
        $this->assertGreaterThanOrEqual(900, DeviantArt::blockedFor());
    }

    public function testAnUnknownSubmissionIsMissing(): void
    {
        $this->fakeApi([], ['www.deviantart.com/deviation/*' => Http::response('', 404)]);

        $this->assertNull(DeviantArt::submission('dabc013'));
        $this->assertTrue(Cache::has('deviation-missing:fav.me:dabc013'));
    }
}
