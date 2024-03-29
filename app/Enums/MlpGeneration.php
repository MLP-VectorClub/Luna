<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="MlpGeneration",
 *   type="string",
 *   description="List of recognized MLP generations",
 *   example="pony"
 * )
 */
enum MlpGeneration: string
{
    use ValuableEnum;

    case FriendshipIsMagic = 'pony';
    case PonyLife = 'pl';
}
