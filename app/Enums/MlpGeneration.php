<?php

namespace App\Enums;

use BenSampo\Enum\Enum;
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
final class MlpGeneration extends Enum
{
    const FriendshipIsMagic = 'pony';
    const PonyLife = 'pl';
}
