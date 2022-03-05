<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="ShowOrdering",
 *   type="string",
 *   description="List of ordering options that can be used for show entries",
 *   example="series",
 * )
 */
enum ShowOrdering: string
{
    use ValuableEnum;

    case Series = 'series';
    case Overall = 'overall';
}
