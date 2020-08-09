<?php

namespace App\Enums;

use BenSampo\Enum\Enum;
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
final class SocialProvider extends Enum
{
    const DeviantArt = 'deviantart';
    const Discord = 'discord';
}
