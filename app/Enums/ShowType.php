<?php

namespace App\Enums;

use App\Utils\EnumWrapper;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="ShowType",
 *     type="string",
 *     description="List of types that can be used for show entries",
 *     enum=SHOW_TYPES,
 *     example="episode",
 * )
 * @method static self Episode()
 * @method static self Movie()
 * @method static self Short()
 * @method static self Special()
 */
final class ShowType extends EnumWrapper
{
    protected static function values(): array
    {
        return [
            'Episode' => 'episode',
            'Movie' => 'movie',
            'Short' => 'short',
            'Special' => 'special',
        ];
    }
}
