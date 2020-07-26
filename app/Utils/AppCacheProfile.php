<?php


namespace App\Utils;


use Illuminate\Http\Request;
use Spatie\ResponseCache\CacheProfiles\CacheAllSuccessfulGetRequests;

class AppCacheProfile extends CacheAllSuccessfulGetRequests
{
    /**
     * Return a string to differentiate this request from others.
     *
     * For example: if you want a different cache per user you could return the id of
     * the logged in user.
     *
     * @param  Request  $request
     *
     * @return mixed
     */
    public function useCacheNameSuffix(Request $request): string
    {
        $route = $request->route();
        if ($route) {
            switch ($route->getName()) {
                case "appearances_all":
                    $guide_name = $request->get('guide');
                    $with_previews = $request->get('previews', false);
                    return Core::generateCacheKey(1, 'appearances all', $guide_name, $with_previews);
            }
        }

        return '';
    }
}
