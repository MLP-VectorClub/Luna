<?php


namespace App\Utils;

use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use OpenApi\Annotations as OA;

class GitHelper
{
    public const CACHE_KEY = 'luna_commit_info';

    protected static function getCommitDataString(): string
    {
        return rtrim(shell_exec('git log -1 --date=short --pretty="format:%h;%ct"'));
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
            ? Cache::get(self::CACHE_KEY, function () {
                $commit_info = self::getCommitDataString();
                Cache::put(self::CACHE_KEY, $commit_info, new \DateInterval('PT1H'));
            })
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
