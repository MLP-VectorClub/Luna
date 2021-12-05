<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="CutieMarkFacing",
 *   type="string",
 *   description="The direction the character is facing when this cutie mark should be used",
 *   enum=CUTIE_MARK_FACINGS,
 *   example="left"
 * )
 */
enum CutieMarkFacing: string
{
    use ValuableEnum;

    case Left = 'left';
    case Right = 'right';
}
