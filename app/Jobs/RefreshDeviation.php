<?php

namespace App\Jobs;

use App\Exceptions\ImageProviderException;
use App\Utils\DeviantArt;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Fetches (or refreshes) the details of a DeviantArt submission into the cache. Pages never wait for DeviantArt: they ask for the cached details and this
 * job fills them in. It is unique per submission, takes at most a few requests per second, and when DeviantArt refuses us it goes back to waiting until
 * the pause {@see DeviantArt::block()} set is over, instead of failing or hammering them.
 */
class RefreshDeviation implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 900;

    public function __construct(public string $id, public string $provider = 'fav.me')
    {
        $this->onQueue('deviantart');
    }

    public function uniqueId(): string
    {
        return "$this->provider:$this->id";
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(): void
    {
        if (($wait = DeviantArt::blockedFor()) > 0) {
            $this->release($wait + random_int(1, 30));

            return;
        }
        // Spread the requests out: 20 a minute is plenty for the details of a page of posts
        if (!RateLimiter::attempt('deviantart-oembed', 20, fn () => true, 60)) {
            $this->release(random_int(3, 10));

            return;
        }

        try {
            DeviantArt::submission($this->id, $this->provider, true);
        } catch (ImageProviderException) {
            // A refused or unreachable DeviantArt: try again after the pause, or in a minute when only this submission failed
            $this->release(max(DeviantArt::blockedFor(), 60) + random_int(1, 30));
        }
    }
}
