<?php

namespace App\Utils;

use App\Exceptions\ImageProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Turns a link to an image on a supported host into preview and full size URLs, like Winterchilla's ImageProvider
 */
class ImageProvider
{
    public const DEVIATION_PROVIDERS = ['dA', 'fav.me'];

    private const PROVIDER_PATTERNS = [
        '(?:[A-Za-z\-\d]+\.)?deviantart\.com/(?:[A-Za-z\-\d]+/)?art/(?:[A-Za-z\-\d]+-)?(\d+)' => 'dA',
        'fav\.me/(d[a-z\d]{6,})' => 'fav.me',
        'sta\.sh/([a-z\d]{10,})' => 'sta.sh',
        '(?:i\.)?imgur\.com/([A-Za-z\d]{1,7})' => 'imgur',
        'derpiboo(?:\.ru|ru\.org)/(\d+)' => 'derpibooru',
        'derpicdn\.net/img/(?:(?:view|download)/)?\d{4}/\d{1,2}/\d{1,2}/(\d+)' => 'derpibooru',
        'prntscr\.com/([\da-z]+)' => 'lightshot',
    ];

    private const ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/jpg'];
    private const BLOCKED_MIME_TYPES = ['image/gif' => 'Animated GIFs'];
    private const STASH_IMAGE_TYPES = ['png', 'jpg', 'jpeg', 'gif'];

    /**
     * @param  string[]|null  $required_providers  restricts the link to these providers
     * @throws ImageProviderException
     */
    public static function resolve(string $url, ?array $required_providers = null, bool $require_image = true): ResolvedImage
    {
        $url = trim(DeviantArt::trimOutgoingGateFromUrl(trim($url)));
        [$provider, $id] = self::detect($url);

        if ($required_providers !== null && !in_array($provider, $required_providers, true)) {
            throw new ImageProviderException('The link points to the wrong provider', $provider);
        }

        return self::load($provider, $id, $require_image);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function detect(string $url): array
    {
        foreach (self::PROVIDER_PATTERNS as $pattern => $name) {
            if (preg_match("~^(?:https?://(?:www\\.)?)?$pattern~", $url, $match)) {
                return [$name, $match[1]];
            }
        }

        throw new ImageProviderException('The image could not be retrieved, the link is not from a supported provider.');
    }

    private static function load(string $provider, string $id, bool $require_image): ResolvedImage
    {
        switch ($provider) {
            case 'imgur':
                $image = new ResolvedImage('imgur', $id, "https://i.imgur.com/{$id}m.png", "https://i.imgur.com/$id.png");
                self::assertAllowedType(self::headContentType($image->fullsize));
                break;

            case 'derpibooru':
                try {
                    $response = Http::timeout(10)->get("https://derpibooru.org/api/v1/json/images/$id");
                } catch (ConnectionException $e) {
                    throw new ImageProviderException('The requested image could not be found on Derpibooru');
                }
                $data = $response->json();
                if (!$response->successful() || empty($data)) {
                    throw new ImageProviderException('The requested image could not be found on Derpibooru');
                }
                if (isset($data['duplicate_of'])) {
                    return self::load('derpibooru', (string) $data['duplicate_of'], $require_image);
                }
                if (!isset($data['processed'])) {
                    throw new ImageProviderException('Derpibooru returned an invalid API response. This issue has been logged, please remind us to take a look.');
                }
                if (!$data['processed']) {
                    throw new ImageProviderException("The image was found but it hasn't been rendered yet. Please wait for it to render and try again shortly.");
                }
                $image = new ResolvedImage('derpibooru', $id, $data['representations']['small'], $data['representations']['full']);
                self::assertAllowedType($data['mime_type'] ?? null);
                break;

            case 'dA':
            case 'fav.me':
            case 'sta.sh':
                if ($provider === 'dA') {
                    $id = 'd'.base_convert($id, 10, 36);
                    $provider = 'fav.me';
                }

                $image = DeviantArt::submission($id, $provider);
                if ($image === null) {
                    throw new ImageProviderException("The specified $provider upload could not be found, please make sure the file exists.");
                }

                $is_image = $provider !== 'sta.sh' || in_array($image->type, self::STASH_IMAGE_TYPES, true);
                if ($is_image) {
                    $failed = [];
                    $broke = false;
                    foreach (['preview', 'fullsize'] as $field) {
                        if ($image->{$field} === null || !DeviantArt::isImageAvailable($image->{$field})) {
                            if ($image->{$field} !== null) {
                                $failed[$field] = $image->{$field};
                            }
                            $broke = true;
                        }
                    }
                    if ($broke) {
                        $message = 'The submission appears to be unavailable. Please'.($failed !== [] ? ' make sure the links below work and' : '').' try again, or re-submit if this persists.';
                        foreach ($failed as $name => $link) {
                            $message .= ' '.ucfirst($name).": $link.";
                        }
                        throw new ImageProviderException($message);
                    }
                } elseif ($require_image) {
                    throw new ImageProviderException('The provided link cannot be used because it does not have an associated image.');
                }

                foreach (['preview', 'fullsize'] as $field) {
                    if ($image->{$field} !== null) {
                        self::assertAllowedType(self::headContentType($image->{$field}));
                    }
                }
                break;

            case 'lightshot':
                try {
                    $page = Http::timeout(10)->get("https://prntscr.com/$id")->body();
                } catch (ConnectionException $e) {
                    $page = '';
                }
                if ($page === '') {
                    throw new ImageProviderException('The requested page could not be found');
                }
                if (!preg_match('~<img\s+class="image__pic[^"]*"\s+src="https?://i\.imgur\.com/([A-Za-z\d]+)\.~', $page, $match)) {
                    throw new ImageProviderException('The requested image could not be found');
                }

                return self::load('imgur', $match[1], $require_image);

            default:
                throw new ImageProviderException("The image could not be retrieved due to a missing handler for the provider \"$provider\"");
        }

        // The test server's image URLs point at local plain HTTP servers
        foreach (DeviantArt::fakeProviders() ? [] : ['preview', 'fullsize'] as $field) {
            if ($image->{$field} !== null) {
                $image->{$field} = preg_replace('~^http://~', 'https://', $image->{$field});
            }
        }

        return $image;
    }

    private static function headContentType(string $url): ?string
    {
        if (DeviantArt::fakeProviders()) {
            return 'image/png';
        }

        try {
            $type = DeviantArt::httpFor($url)->head($url)->header('Content-Type');
        } catch (ConnectionException $e) {
            throw new ImageProviderException("Resource URL ($url) could not be reached, please try again.");
        }

        return $type === '' ? null : strtolower(trim(explode(';', $type)[0]));
    }

    private static function assertAllowedType(?string $type): void
    {
        if ($type === null || !in_array($type, self::ALLOWED_MIME_TYPES, true)) {
            $what = isset(self::BLOCKED_MIME_TYPES[$type]) ? self::BLOCKED_MIME_TYPES[$type].' are' : 'Content type "'.($type ?? 'unknown').'" is';
            throw new ImageProviderException("$what not allowed, please use a different image.");
        }
    }
}
