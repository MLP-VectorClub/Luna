<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="TagType",
 *     type="string",
 *     description="List of types tags in the color guide can have",
 *     enum=TAG_TYPES,
 *     example="spec"
 * )
 */
enum TagType: string
{
    case Clothing = 'app';
    case Category = 'cat';
    case Gender = 'gen';
    case Species = 'spec';
    case Character = 'char';
    case Warning = 'warn';
}
