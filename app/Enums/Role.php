<?php

namespace App\Enums;

use BenSampo\Enum\Enum;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="DatabaseRole",
 *   type="string",
 *   description="List of roles values that can be stored by the backend",
 *   enum=DATABASE_ROLES,
 *   example="developer",
 * )
 * @OA\Schema(
 *   schema="Role",
 *   type="string",
 *   description="List of roles values that can be publicly displayed",
 *   enum=CLIENT_ROLES,
 *   example="user",
 * )
 */
final class Role extends Enum
{
    const User = 'user';
    const Member = 'member';
    const Assistant = 'assistant';
    const Staff = 'staff';
    const Admin = 'admin';
    const Developer = 'developer';
}
