<?php

namespace App\Enums;

use BenSampo\Enum\Enum;
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
final class AvatarProvider extends Enum
{
    const DeviantArt = 'deviantart';
    const Discord = 'discord';
    const Gravatar = 'gravatar';
}
