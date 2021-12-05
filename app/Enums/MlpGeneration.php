<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="MlpGeneration",
 *   type="string",
 *   description="List of recognized MLP generations",
 *   enum=MLP_GENERATIONS,
 *   example="pony"
 * )
 */
enum MlpGeneration: string
{
    case FriendshipIsMagic = 'pony';
    case PonyLife = 'pl';
}
