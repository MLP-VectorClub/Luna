<?php

namespace App\Enums;

use BenSampo\Enum\Enum;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="VectorApp",
 *     type="string",
 *     description="List of available vector apps",
 *     enum=VECTOR_APPS,
 *     example="illustrator"
 * )
 * @method static self Illustrator()
 * @method static self Inkscape()
 * @method static self Ponyscape()
 */
final class VectorApp extends Enum
{
    const Illustrator = 'illustrator';
    const Inkscape = 'inkscape';
    const Ponyscape = 'ponyscape';
}
