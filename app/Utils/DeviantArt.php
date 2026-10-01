<?php

namespace App\Utils;

use App\Exceptions\ImageProviderException;
use Illuminate\Http\Client\ConnectionException;
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
    public static function submission(string $id, string $provider = 'fav.me'): ?ResolvedImage
    {
        if ($provider === 'sta.sh') {
            $id = self::normalizeStashId($id);
        }

        $cache_key = "deviation:$provider:$id";
        $cached = Cache::get($cache_key);
        if ($cached !== null) {
            return new ResolvedImage(...$cached);
        }

        $url = $provider === 'sta.sh' ? "https://sta.sh/$id" : "https://fav.me/$id";
        try {
            $response = Http::timeout(10)->get(self::OEMBED_URL, ['url' => $url]);
        } catch (ConnectionException $e) {
            throw new ImageProviderException('Image could not be retrieved; '.$e->getMessage());
        }

        if ($response->status() === 404) {
            return null;
        }
        if ($response->status() === 403) {
            Log::error("DeviantArt denied access to $url");
            throw new ImageProviderException('Got access denied while loading image');
        }
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

        return $image;
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
     * @param  int[]  $only_fails  when given, only these response codes count as unavailable
     */
    public static function isImageAvailable(string $url, array $only_fails = [], ?int &$response_code = null): bool
    {
        try {
            $response = Http::timeout(10)->head($url);
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
        $numeric_id = intval(mb_substr($deviation_id, 1), 36);
        try {
            $response = Http::timeout(10)->get('https://www.deviantart.com/global/difi/', ['c' => ["\"DeviationView\",\"getAllGroups\",[\"$numeric_id\"]"], 't' => 'json']);
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
