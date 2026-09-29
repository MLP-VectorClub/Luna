<?php


namespace App\Utils;

use Carbon\Carbon;
use DateInterval;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use OpenApi\Annotations as OA;

class GitHelper
{
    public const CACHE_KEY = 'commit_info';

    protected static function getCommitDataString(): string
    {
        // git-deploy-toolkit leaves the deployed commit in this file on every deploy (sha, then the commit date),
        // since the deployed tree has no .git of its own that would know which commit is running.
        $file = base_path('.git-deploy-commit');
        if (is_readable($file)) {
            [$sha, $date] = array_pad(explode("\n", trim(file_get_contents($file))), 2, '');
            $time = strtotime($date);
            if (preg_match('/^[0-9a-f]{40}$/', $sha) === 1 && $time !== false) {
                return substr($sha, 0, 7).';'.$time;
            }
        }

        return rtrim((string) shell_exec('git log -1 --date=short --pretty="format:%h;%ct"'));
    }

    /**
     * Returns the cached Git version information
     *
     * @OA\Schema(
     *   schema="CommitData",
     *   type="object",
     *   description="An object containing information related to the verion of this appilcation that's currently running on the server",
     *   required={
     *     "commitId",
     *     "commitTime",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="commitId",
     *     type="string",
     *     description="Abbreviated commit ID of the backend application, indicating the version currently deployed on the server (at least 7 characters long)",
     *     example="50ce2e2",
     *     nullable=true,
     *   ),
     *   @OA\Property(
     *     property="commitTime",
     *     nullable=true,
     *     description="Date at which the commit currently deployed on the server was authored",
     *     allOf={
     *       @OA\Schema(ref="#/components/schemas/IsoStandardDate")
     *     }
     *   ),
     * )
     *
     * @return array = [
     *     'commit_id' => string,
     *     'commit_time' => new Carbon,
     * ]
     */
    public static function getCommitData(): array
    {
        $commit_info = App::isProduction()
            ? Cache::remember(self::CACHE_KEY, new DateInterval('PT1H'), fn () => self::getCommitDataString())
            : self::getCommitDataString();

        $data = [];
        if (!empty($commit_info)) {
            [$commit_id, $commit_time] = explode(';', $commit_info);
            $data['commit_id'] = $commit_id;
            $data['commit_time'] = (new Carbon())->setTimestamp($commit_time);
        }

        return $data;
    }

    public static function clearCommitDataCache(): bool
    {
        if (Cache::missing(self::CACHE_KEY)) {
            return true;
        }

        return Cache::delete(self::CACHE_KEY);
    }
}
