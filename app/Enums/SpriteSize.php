<?php

namespace App\Enums;

use BenSampo\Enum\Enum;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="SpriteSize",
 *   type="number",
 *   description="List of available sprite sizes",
 *   enum=SPRITE_SIZES,
 *   example=300
 * )
 */
final class SpriteSize extends Enum
{
    const Default = 300;
    const Double = 600;
}
