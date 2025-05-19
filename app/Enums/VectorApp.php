<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="VectorApp",
 *     type="string",
 *     description="List of available vector apps",
 *     example="illustrator"
 * )
 * @method static self Illustrator()
 * @method static self Inkscape()
 * @method static self Ponyscape()
 */
enum VectorApp: string
{
    use ValuableEnum;

    case Illustrator = 'illustrator';
    case Inkscape = 'inkscape';
    case Ponyscape = 'ponyscape';
}
