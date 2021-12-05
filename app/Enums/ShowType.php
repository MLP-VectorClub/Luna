<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="ShowType",
 *   type="string",
 *   description="List of types that can be used for show entries",
 *   enum=SHOW_TYPES,
 *   example="episode",
 * )
 */
enum ShowType: string
{
    use ValuableEnum;

    case Episode = 'episode';
    case Movie = 'movie';
    case Short = 'short';
    case Special = 'special';
}
