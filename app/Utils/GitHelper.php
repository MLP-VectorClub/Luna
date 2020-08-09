<?php


namespace App\Utils;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;

class GitHelper
{
    const CACHE_KEY = 'celestia_commit_info';

    protected static function getCommitDataString(): string
    {
        return rtrim(shell_exec('git log -1 --date=short --pretty="format:%h;%ci"'));
    }

    /**
     * Returns the cached Git version information
     *
     * @return array = [
     *     'commit_id' => string,
     *     'commit_time' => int,
     * ]
     */
    public static function getCommitData(): array
    {
        $commit_info = App::isProduction()
            ? Cache::get(self::CACHE_KEY, function () {
                $commit_info = self::getCommitDataString();
                Cache::put(self::CACHE_KEY, $commit_info, new \DateInterval('PT1H'));
            })
            : self::getCommitDataString();

        $data = [];
        if (!empty($commit_info)) {
            [$commit_id, $commit_time] = explode(';', $commit_info);
            $data['commit_id'] = $commit_id;
            $data['commit_time'] = (int) $commit_time;
        }

        return $data;
    }
}
