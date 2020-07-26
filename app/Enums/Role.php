<?php

namespace App\Enums;

use App\Utils\EnumWrapper;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="DatabaseRole",
 *     type="string",
 *     description="List of roles values that can be stored by the backend",
 *     enum=DATABASE_ROLES,
 *     example="developer",
 * )
 * @OA\Schema(
 *     schema="Role",
 *     type="string",
 *     description="List of roles values that can be publicly displayed",
 *     enum=CLIENT_ROLES,
 *     example="user",
 * )
 * @method static self User()
 * @method static self Member()
 * @method static self Assistant()
 * @method static self Staff()
 * @method static self Admin()
 * @method static self Developer()
 */
final class Role extends EnumWrapper
{
    protected static function values(): array
    {
        return [
            'User' => 'user',
            'Member' => 'member',
            'Assistant' => 'assistant',
            'Staff' => 'staff',
            'Admin' => 'admin',
            'Developer' => 'developer',
        ];
    }
}
