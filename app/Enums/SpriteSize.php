<?php

namespace App\Enums;

use App\Utils\EnumWrapper;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="SpriteSize",
 *     type="string",
 *     description="List of available sprite sizes",
     *   enum=SPRITE_SIZES,
 *     example="300"
 * )
 * @method static self Default()
 * @method static self Double()
 */
final class SpriteSize extends EnumWrapper
{
    protected static function values(): array
    {
        return [
            'Default' => 300,
            'Double' => 600,
        ];
    }
}
