<?php

namespace App\Enums;

use OpenApi\Attributes as OA;

/**
 * @method static self Illustrator()
 * @method static self Inkscape()
 * @method static self Ponyscape()
 * @method static self AffinityDesigner()
 */
#[OA\Schema(schema: 'VectorApp', type: 'string', description: 'List of available vector apps', example: 'illustrator')]
enum VectorApp: string
{
    use ValuableEnum;

    case Illustrator = 'illustrator';
    case Inkscape = 'inkscape';
    case Ponyscape = 'ponyscape';
    case AffinityDesigner = 'affinity-designer';
}
