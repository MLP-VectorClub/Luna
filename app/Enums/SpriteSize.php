<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="SpriteSize",
 *   type="number",
 *   description="List of available sprite sizes",
 *   example=300
 * )
 */
enum SpriteSize: int
{
    use ValuableEnum;

    case Default = 300;
    case Double = 600;
}
