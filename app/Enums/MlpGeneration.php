<?php

namespace App\Enums;

use App\Utils\EnumWrapper;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="MlpGeneration",
 *     type="string",
 *     description="List of recognized MLP generations",
 *     enum=MLP_GENERATIONS,
 *     example="pony"
 * )
 * @method static self FriendshipIsMagic()
 * @method static self PonyLife()
 */
final class MlpGeneration extends EnumWrapper
{
    protected static function values(): array
    {
        return [
            'FriendshipIsMagic' => 'pony',
            'PonyLife' => 'pl',
        ];
    }
}