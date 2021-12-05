<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="AvatarProvider",
 *   type="string",
 *   description="List of supported avatar providers",
 *   enum=AVATAR_PROVIDERS,
 *   example="deviantart"
 * )
 */
enum AvatarProvider: string
{
    use ValuableEnum;

    case DeviantArt = 'deviantart';
    case Discord = 'discord';
    case Gravatar = 'gravatar';
}
