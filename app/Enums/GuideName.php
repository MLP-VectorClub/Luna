<?php

namespace App\Enums;

use App\Utils\EnumWrapper;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="GuideName",
 *     type="string",
 *     description="List of available color guides",
 *     enum=GUIDE_NAMES,
 *     example="pony"
 * )
 * @method static self FriendshipIsMagic()
 * @method static self EquestriaGirls()
 * @method static self PonyLife()
 */
final class GuideName extends EnumWrapper
{
    protected static function values(): array
    {
        return [
            'FriendshipIsMagic' => 'pony',
            'EquestriaGirls' => 'eqg',
            'PonyLife' => 'pl',
        ];
    }
}
