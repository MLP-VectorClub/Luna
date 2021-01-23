<?php

namespace App\Enums;

use BenSampo\Enum\Enum;
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
final class ShowOrdering extends Enum
{
    const Series = 'series';
    const Overall = 'overall';
}
