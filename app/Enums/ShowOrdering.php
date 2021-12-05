<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="ShowOrdering",
 *   type="string",
 *   description="List of ordering options that can be used for show entries",
 *   enum=SHOW_ORDERING,
 *   example="series",
 * )
 */
enum ShowOrdering: string
{
    use ValuableEnum;

    case Series = 'series';
    case Overall = 'overall';
}
