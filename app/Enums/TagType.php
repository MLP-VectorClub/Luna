<?php

namespace App\Enums;

use BenSampo\Enum\Enum;
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
final class TagType extends Enum
{
    const Clothing = 'app';
    const Category = 'cat';
    const Gender = 'gen';
    const Species = 'spec';
    const Character = 'char';
    const Warning = 'warn';
}
