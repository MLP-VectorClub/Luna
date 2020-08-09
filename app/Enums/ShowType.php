<?php

namespace App\Enums;

use BenSampo\Enum\Enum;
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
final class ShowType extends Enum
{
    const Episode = 'episode';
    const Movie = 'movie';
    const Short = 'short';
    const Special = 'special';
}
