<?php

namespace App\Console\Commands;

use App\Jobs\RefreshDeviation;
use App\Models\Post;
use App\Utils\DeviantArt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DeviantArtWarmDeviations extends Command
{
    protected $signature = 'deviantart:warm-deviations {--limit=40 : How many submissions to queue per run}';

    protected $description = 'Queues the fetching of DeviantArt details that finished posts need and are missing or older than a week';

    public function handle(): int
    {
        if (DeviantArt::blockedFor() > 0) {
            $this->info('DeviantArt is being left alone for now.');

            return self::SUCCESS;
        }

        $queued = 0;
        Post::whereNotNull('deviation_id')->where('broken', false)->orderByDesc('id')->select(['id', 'deviation_id'])->lazyById(500)
            ->each(function (Post $post) use (&$queued) {
                if (Cache::has("deviation-fresh:fav.me:$post->deviation_id") || Cache::has("deviation-missing:fav.me:$post->deviation_id")) {
                    return true;
                }
                RefreshDeviation::dispatch($post->deviation_id);

                return ++$queued < (int) $this->option('limit');
            });
        $this->info("Queued $queued submissions.");

        return self::SUCCESS;
    }
}
