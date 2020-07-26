<?php

namespace App\Enums;

use App\Utils\EnumWrapper;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="AvatarProvider",
 *     type="string",
 *     description="List of supported avatar providers",
 *     enum=AVATAR_PROVIDERS,
 *     example="deviantart"
 * )
 * @method static self DeviantArt()
 * @method static self Discord()
 * @method static self Gravatar()
 */
final class AvatarProvider extends EnumWrapper
{
    protected static function values(): array
    {
        return [
            'DeviantArt' => 'deviantart',
            'Discord' => 'discord',
            'Gravatar' => 'gravatar',
        ];
    }
}
