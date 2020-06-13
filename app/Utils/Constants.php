<?php


namespace App\Utils;

use OpenApi\Annotations as OA;

class Constants
{
    /**
     * @OA\Schema(
     *     schema="AvatarProvider",
     *     type="string",
     *     description="List of supported avatar providers",
     *     enum=AVATAR_PROVIDERS,
     * )
     */
    public const AVATAR_PROVIDERS = [
        'gravatar',
        // 'deviantart',
        // 'discord',
    ];
}
