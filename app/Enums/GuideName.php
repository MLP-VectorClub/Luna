<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="GuideName",
 *   type="string",
 *   description="List of available color guides",
 *   example="pony"
 * )
 */
enum GuideName: string
{
    use ValuableEnum;

    case FriendshipIsMagic = 'pony';
    case EquestriaGirls = 'eqg';
    case PonyLife = 'pl';
}
