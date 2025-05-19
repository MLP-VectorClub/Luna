<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="TagType",
 *     type="string",
 *     description="List of types tags in the color guide can have",
 *     example="spec"
 * )
 */
enum TagType: string
{
    use ValuableEnum;

    case Clothing = 'app';
    case Category = 'cat';
    case Gender = 'gen';
    case Species = 'spec';
    case Character = 'char';
    case Warning = 'warn';
}
