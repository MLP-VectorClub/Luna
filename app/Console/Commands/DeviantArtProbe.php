<?php

namespace App\Console\Commands;

use App\Utils\DeviantArt;
use App\Utils\DeviantArtTokens;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class DeviantArtProbe extends Command
{
    protected $signature = 'deviantart:probe {id : A short id (d9abc12), a fav.me or deviantart.com deviation link} {--uuid= : Skip the page lookup and use this API id}';

    protected $description = 'Checks whether a deviation can be resolved to its API id and read through the official API (nothing is stored)';

    public function handle(): int
    {
        $input = trim($this->argument('id'));
        $uuid = $this->option('uuid');

        if (!$uuid) {
            $url = $this->pageUrl($input);
            if ($url === null) {
                return self::FAILURE;
            }
            $this->line("Page: $url (proxy: ".(config('services.deviantart.proxy') ?: 'none').')');
            try {
                $page = DeviantArt::http()->get($url);
            } catch (ConnectionException $e) {
                $this->error('Page request failed: '.$e->getMessage());

                return self::FAILURE;
            }
            $this->line('Page status: '.$page->status());
            if (!$page->successful()) {
                return self::FAILURE;
            }
            $html = $page->body();
            // Candidates, so the output shows which markup carries it
            $found = [];
            if (preg_match('~content="DeviantArt://deviation/([0-9A-F-]{36})"~i', $html, $m)) {
                $found['da:appurl meta'] = $m[1];
            }
            if (preg_match('~"deviationUuid"\s*:\s*"([0-9A-F-]{36})"~i', $html, $m)) {
                $found['deviationUuid json'] = $m[1];
            }
            if (preg_match('~deviation/([0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12})~i', $html, $m)) {
                $found['deviation/<uuid> anywhere'] = $m[1];
            }
            if ($found === []) {
                $this->error('No UUID found in the page.');

                return self::FAILURE;
            }
            foreach ($found as $where => $value) {
                $this->line("  $where: $value");
            }
            $uuid = reset($found);
        }

        $token = Http::asForm()->withUserAgent(config('services.deviantart.user_agent'))->timeout(15)->post(DeviantArtTokens::TOKEN_URL, [
            'grant_type' => 'client_credentials',
            'client_id' => config('services.deviantart.client_id'),
            'client_secret' => config('services.deviantart.client_secret'),
        ]);
        $this->line('Token status: '.$token->status());
        if (!$token->successful() || !$token->json('access_token')) {
            $this->error('No app token: '.$token->body());

            return self::FAILURE;
        }

        $api = Http::withToken($token->json('access_token'))->withUserAgent(config('services.deviantart.user_agent'))->timeout(15)
            ->get("https://www.deviantart.com/api/v1/oauth2/deviation/$uuid");
        $this->line('API status: '.$api->status().' (Retry-After: '.($api->header('Retry-After') ?: '-').')');
        if (!$api->successful()) {
            $this->line($api->body());

            return self::FAILURE;
        }
        $json = $api->json();
        $this->table(['field', 'value'], [
            ['deviationid', $json['deviationid'] ?? '(missing)'],
            ['url', $json['url'] ?? '(missing)'],
            ['title', $json['title'] ?? '(missing)'],
            ['author.username', $json['author']['username'] ?? '(missing)'],
            ['content.src', $json['content']['src'] ?? '(missing)'],
            ['content size', ($json['content']['width'] ?? '?').'x'.($json['content']['height'] ?? '?')],
            ['thumbs', count($json['thumbs'] ?? [])],
            ['preview.src', $json['preview']['src'] ?? '(missing)'],
            ['is_deleted', json_encode($json['is_deleted'] ?? null)],
        ]);

        return self::SUCCESS;
    }

    private function pageUrl(string $input): ?string
    {
        if (preg_match('~^https?://(?:www\.)?deviantart\.com/~i', $input)) {
            return $input;
        }
        if (preg_match('~^(?:https?://)?fav\.me/(d[0-9a-z]+)$~i', $input, $m)) {
            $input = $m[1];
        }
        if (preg_match('~^d[0-9a-z]+$~i', $input)) {
            // www.deviantart.com/deviation/<numeric id> redirects to the full address
            return 'https://www.deviantart.com/deviation/'.intval(substr($input, 1), 36);
        }
        $this->error('Not a short id or a deviantart.com / fav.me link.');

        return null;
    }
}
