<?php

namespace App\Enums;

use BenSampo\Enum\Enum;
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
final class CutieMarkFacing extends Enum
{
    const Left = 'left';
    const Right = 'right';
}
