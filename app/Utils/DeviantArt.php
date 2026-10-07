<?php

namespace App\Utils;

use App\Exceptions\ImageProviderException;
use App\Jobs\RefreshDeviation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The parts of DeviantArt that posts depend on: submission details (through oEmbed), image availability and whether a
 * deviation was accepted into the club's gallery.
 */
class DeviantArt
{
    private const OEMBED_URL = 'https://backend.deviantart.com/oembed';
    private const CLUB_GALLERY_MARKER = 'gmi-groupname="MLP-VectorClub">';

    /** A request to DeviantArt: through the configured proxy ({@see config('services.deviantart.proxy')}), when there is one */
    public static function http(): PendingRequest
    {
        $proxy = config('services.deviantart.proxy');

        return Http::timeout(10)->when($proxy, fn (PendingRequest $request) => $request->withOptions(['proxy' => $proxy]));
    }

    /** The client for any address: proxied when it is on one of DeviantArt's own hosts, direct for other image hosts */
    public static function httpFor(string $url): PendingRequest
    {
        return self::isDeviantArtHost($url) ? self::http() : Http::timeout(10);
    }

    private static function isDeviantArtHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return (bool) preg_match('~(^|\.)(deviantart\.(com|net)|wixmp\.com|sta\.sh|fav\.me)$~', $host);
    }

    public static function trimOutgoingGateFromUrl(string $url): string
    {
        return preg_replace('~^https?://(www\.)?deviantart\.com/users/outgoing\?~', '', $url);
    }

    public static function normalizeStashId(string $id): string
    {
        $normalized = ltrim($id, '0');

        return mb_strlen($normalized) < 12 ? '0'.$normalized : $normalized;
    }

    /**
     * @return ResolvedImage|null null when the submission does not exist
     */
    public const CLUB_GALLERY_CACHE_PREFIX = 'test-club-gallery:';

    /** The answer of {@see lookup()} while a background job is still fetching the submission */
    public const PENDING = 'pending';

    private const BLOCKED_KEY = 'deviantart:blocked-until';
    private const BLOCK_LEVEL_KEY = 'deviantart:block-level';
    private const DENIALS_KEY = 'deviantart:denials';
    /** Distinct denials within a couple of minutes that mean DeviantArt is refusing us in general, not just that one submission */
    private const DENIALS_BEFORE_BLOCK = 3;

    /**
     * Seconds until DeviantArt may be asked again. After it refused us (403/429/5xx) we stay quiet with a growing pause (5 minutes, doubling up to 2 hours,
     * or whatever Retry-After said) instead of sending every visitor's request into the same wall
     */
    public static function blockedFor(): int
    {
        return max(0, (int) Cache::get(self::BLOCKED_KEY, 0) - time());
    }

    public static function block(?int $retry_after = null): void
    {
        if (self::blockedFor() > 0) {
            return;
        }
        $level = (int) Cache::get(self::BLOCK_LEVEL_KEY, 0);
        $seconds = max($retry_after ?? 0, min(7200, 300 * 2 ** $level));
        Cache::put(self::BLOCK_LEVEL_KEY, $level + 1, now()->addHours(6));
        Cache::put(self::BLOCKED_KEY, time() + $seconds, $seconds);
        Log::warning("DeviantArt is refusing requests, not asking again for $seconds seconds");
    }

    private static function countDenial(): void
    {
        Cache::add(self::DENIALS_KEY, 0, 120);
        if (Cache::increment(self::DENIALS_KEY) >= self::DENIALS_BEFORE_BLOCK) {
            Cache::forget(self::DENIALS_KEY);
            self::block();
        }
    }

    public static function cachedSubmission(string $id, string $provider = 'fav.me'): ?ResolvedImage
    {
        if ($provider === 'sta.sh') {
            $id = self::normalizeStashId($id);
        }
        $cached = Cache::get("deviation:$provider:$id");

        return $cached === null ? null : new ResolvedImage(...$cached);
    }

    /**
     * What the pages show for a submission without ever making the visitor wait for DeviantArt: the cached details, or {@see PENDING} while the queue fetches
     * them (or a refresh of details older than a week, which are served meanwhile). With the sync queue the job runs right here, so a failure is thrown.
     *
     * @return ResolvedImage|string|null null when the submission does not exist
     * @throws ImageProviderException when DeviantArt could not be asked and nothing is cached
     */
    public static function lookup(string $id, string $provider = 'fav.me'): ResolvedImage|string|null
    {
        $cached = self::cachedSubmission($id, $provider);
        if (self::fakeProviders()) {
            return $cached;
        }
        $key = $provider === 'sta.sh' ? self::normalizeStashId($id) : $id;
        // Details that are merely old are served as they are; refreshing them is the queue's business, never a visitor's wait
        $sync = config('queue.default') === 'sync';
        if ($cached !== null && ($sync || Cache::has("deviation-fresh:$provider:$key"))) {
            return $cached;
        }
        if ($cached === null && Cache::has("deviation-missing:$provider:$key")) {
            return null;
        }

        RefreshDeviation::dispatch($id, $provider);

        $cached = self::cachedSubmission($id, $provider);
        if ($cached !== null) {
            return $cached;
        }
        if (Cache::has("deviation-missing:$provider:$key")) {
            return null;
        }
        if ($sync) {
            throw new ImageProviderException('Image could not be retrieved right now');
        }

        return self::PENDING;
    }

    /**
     * @param  bool  $force  ask DeviantArt even when the details are cached (a failed refresh leaves the old details in place)
     * @return ResolvedImage|null null when the submission does not exist
     * @throws ImageProviderException
     */
    public static function submission(string $id, string $provider = 'fav.me', bool $force = false): ?ResolvedImage
    {
        if ($provider === 'sta.sh') {
            $id = self::normalizeStashId($id);
        }

        $cache_key = "deviation:$provider:$id";
        $cached = $force ? null : Cache::get($cache_key);
        if ($cached !== null) {
            return new ResolvedImage(...$cached);
        }
        if (self::fakeProviders()) {
            return null;
        }
        if (!$force && Cache::has("deviation-missing:$provider:$id")) {
            return null;
        }
        if (Cache::has("deviation-failed:$provider:$id")) {
            throw new ImageProviderException('Image could not be retrieved; DeviantArt refused this submission a moment ago');
        }
        if (self::blockedFor() > 0) {
            throw new ImageProviderException('Image could not be retrieved; DeviantArt is busy, try again later');
        }

        // oEmbed does not accept fav.me short links (it answers 404 "not a deviation URL"), they are resolved to the full address first, and only
        // http://fav.me redirects at all, https://fav.me answers with nothing
        $url = $provider === 'sta.sh' ? "http://sta.sh/$id" : self::resolveFavMe($id, $provider);
        if ($url === null) {
            Cache::put("deviation-missing:$provider:$id", true, now()->addDay());

            return null;
        }
        try {
            $response = self::http()->get(self::OEMBED_URL, ['url' => $url]);
        } catch (ConnectionException $e) {
            throw new ImageProviderException('Image could not be retrieved; '.$e->getMessage());
        }

        if ($response->status() === 404) {
            Cache::put("deviation-missing:$provider:$id", true, now()->addDay());

            return null;
        }
        self::checkRefusal($response, $provider, $id);
        if (!$response->successful() || empty($response->json())) {
            throw new ImageProviderException('Image could not be retrieved; the server answered '.$response->status());
        }

        $json = $response->json();
        $https = fn(?string $link) => $link === null ? null : preg_replace('~^http://~', 'https://', $link);
        $image = new ResolvedImage(
            provider: $provider,
            id: $id,
            preview: $https($json['thumbnail_url'] ?? null),
            fullsize: $https($json['fullsize_url'] ?? (isset($json['url']) && !preg_match('/-\d+$/', $json['url']) ? $json['url'] : null)),
            title: str_replace("\\'", "'", $json['title'] ?? ''),
            author: $json['author_name'] ?? null,
            type: self::detectType($json),
        );
        // Without a separate full size version the preview is the best we have
        $image->fullsize ??= $image->preview;

        Cache::put($cache_key, get_object_vars($image), now()->addDays(30));
        Cache::put("deviation-fresh:$provider:$id", true, now()->addDays(7));
        Cache::forget(self::BLOCK_LEVEL_KEY);

        return $image;
    }

    /**
     * The full address a fav.me short link leads to (kept for a month, it does not change), null when the link leads nowhere
     *
     * @throws ImageProviderException
     */
    private static function resolveFavMe(string $id, string $provider): ?string
    {
        $cache_key = "deviation-url:$id";
        if (($cached = Cache::get($cache_key)) !== null) {
            return $cached;
        }

        try {
            $response = self::http()->withoutRedirecting()->get("http://fav.me/$id");
        } catch (ConnectionException $e) {
            throw new ImageProviderException('Image could not be retrieved; '.$e->getMessage());
        }
        $location = $response->header('Location');
        if ($response->redirect() && $location !== '') {
            Cache::put($cache_key, $location, now()->addDays(30));

            return $location;
        }
        self::checkRefusal($response, $provider, $id);
        if (!$response->successful() && $response->status() !== 404) {
            throw new ImageProviderException('Image could not be retrieved; the server answered '.$response->status());
        }

        return null;
    }

    /**
     * Turns the answers that mean DeviantArt is refusing us into pauses and exceptions
     *
     * @throws ImageProviderException
     */
    private static function checkRefusal(\Illuminate\Http\Client\Response $response, string $provider, string $id): void
    {
        if ($response->status() === 429) {
            self::block((int) $response->header('Retry-After'));
            throw new ImageProviderException('Image could not be retrieved; DeviantArt asked us to slow down');
        }
        if ($response->status() === 403) {
            Cache::put("deviation-failed:$provider:$id", true, now()->addMinutes(30));
            self::countDenial();
            throw new ImageProviderException('Got access denied while loading image');
        }
        if ($response->serverError()) {
            self::countDenial();
        }
    }

    private static function detectType(array $json): ?string
    {
        switch ($json['type'] ?? null) {
            case 'photo':
                return !empty($json['imagetype']) ? $json['imagetype'] : strtolower(pathinfo(strtok($json['url'] ?? '', '?'), PATHINFO_EXTENSION));
            case 'rich':
                $html = $json['html'] ?? '';
                if (preg_match('/\sdata-extension="([a-z\d]+?)"/', $html, $match)) {
                    return $match[1];
                }
                if (preg_match('~<h2>([A-Z\d]+?)</h2>~', $html, $match)) {
                    return strtolower($match[1]);
                }

                return null;
            default:
                return $json['imagetype'] ?? null;
        }
    }

    /**
     * Test server mode (APP_ENV=testing and TEST_FAKE_PROVIDERS=true): nothing is asked of DeviantArt or of image hosts. Deviations come from the cache
     * (see TestFixturesController), images count as available PNGs and the club gallery is a cache marker per deviation
     */
    public static function fakeProviders(): bool
    {
        return App::environment('testing') && config('app.test_providers');
    }

    /**
     * @param  int[]  $only_fails  when given, only these response codes count as unavailable
     */
    public static function isImageAvailable(string $url, array $only_fails = [], ?int &$response_code = null): bool
    {
        if (self::fakeProviders()) {
            $response_code = 200;

            return true;
        }
        // DeviantArt's image host answers 401 (or 410) once the token in an image address stops working, such an image is gone for good
        // (403 is not counted: it can just as well mean that the host dislikes the address we ask from)
        if ($only_fails !== [] && self::isDeviantArtHost($url)) {
            $only_fails = array_values(array_unique([...$only_fails, 401, 410]));
        }
        try {
            $response = self::httpFor($url)->head($url);
        } catch (ConnectionException $e) {
            $response_code = 0;

            return false;
        }
        $response_code = $response->status();
        if ($response->successful()) {
            return true;
        }

        return $only_fails !== [] && !in_array($response_code, $only_fails, true);
    }

    /**
     * @return true|false|int true when the deviation is in the club gallery, false when it is not, an error code when that could not be determined
     */
    public static function isDeviationInClub(string $deviation_id): bool|int
    {
        if (self::fakeProviders()) {
            return Cache::has(self::CLUB_GALLERY_CACHE_PREFIX.$deviation_id);
        }

        $numeric_id = intval(mb_substr($deviation_id, 1), 36);
        try {
            $response = self::http()->get('https://www.deviantart.com/global/difi/', ['c' => ["\"DeviationView\",\"getAllGroups\",[\"$numeric_id\"]"], 't' => 'json']);
        } catch (ConnectionException $e) {
            return 1;
        }
        if (!$response->successful()) {
            return $response->status();
        }

        $call = $response->json('DiFi.response.calls.0.response');
        if ($response->json('DiFi.status') !== 'SUCCESS' || ($call['status'] ?? null) !== 'SUCCESS' || empty($call['content']['html'])) {
            return 2;
        }

        return str_contains($call['content']['html'], self::CLUB_GALLERY_MARKER);
    }
}
