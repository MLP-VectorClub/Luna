<?php

namespace App\Enums;

use App\Utils\EnumWrapper;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="TagType",
 *     type="string",
 *     description="List of types tags in the color guide can have",
 *     enum=TAG_TYPES,
 *     example="spec"
 * )
 * @method static self Clothing()
 * @method static self Category()
 * @method static self Gender()
 * @method static self Species()
 * @method static self Character()
 * @method static self Warning()
 */
final class TagType extends EnumWrapper
{
    protected static function values(): array
    {
        return [
            'Clothing' => 'app',
            'Category' => 'cat',
            'Gender' => 'gen',
            'Species' => 'spec',
            'Character' => 'char',
            'Warning' => 'warn',
        ];
    }
}
