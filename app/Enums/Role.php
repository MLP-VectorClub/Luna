<?php

namespace App\Enums;

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
enum Role: string
{
    use ValuableEnum;

    case User = 'user';
    case Member = 'member';
    case Assistant = 'assistant';
    case Staff = 'staff';
    case Admin = 'admin';
    case Developer = 'developer';
}
