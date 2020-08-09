<?php

namespace App\Enums;

use BenSampo\Enum\Enum;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="GuideName",
 *   type="string",
 *   description="List of available color guides",
 *   enum=GUIDE_NAMES,
 *   example="pony"
 * )
 */
final class GuideName extends Enum
{
    const FriendshipIsMagic = 'pony';
    const EquestriaGirls = 'eqg';
    const PonyLife = 'pl';
}
