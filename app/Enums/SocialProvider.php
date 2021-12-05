<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="SocialProvider",
 *   type="string",
 *   description="List of available social signin providers",
 *   enum=SOCIAL_PROVIDERS,
 *   example="deviantart"
 * )
 */
enum SocialProvider: string
{
    use ValuableEnum;

    case DeviantArt = 'deviantart';
    case Discord = 'discord';
}
