<?php

declare(strict_types=1);

namespace App\Utils;

use OpenApi\Annotations as OA;
use RuntimeException;
use function array_key_exists;

class Permission
{
    public const ROLES = [
        'user' => 1,
        'member' => 2,
        'assistant' => 3,
        'staff' => 3,
        'admin' => 3,
        'developer' => 255,
    ];

    /**
     * Permission checking function
     * ----------------------------
     * Compares the currently logged in user's role to the one specified
     * A "true" return value means that the user meets the required role or surpasses it.
     * If user isn't logged in, and $compareAgainst is missing, returns false
     * If $compareAgainst is set then $role is used as the current user's role
     *
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
     *
     * @param  string  $role
     * @param  string|null  $compareAgainst
     *
     * @return bool
     */
    public static function sufficient(string $role, ?string $compareAgainst = null): bool
    {
        if (!isset(self::ROLES[$role])) {
            throw new RuntimeException("Invalid role: $role");
        }

        $comparison = $compareAgainst !== null;

        if ($comparison) {
            $check_role = $compareAgainst;
        } else {
            if (!Auth::$signed_in) {
                return false;
            }
            $check_role = Auth::$user->role;
        }

        return self::ROLES[$check_role] >= self::ROLES[$role];
    }

    /**
     * Same as above, except the return value is inverted
     * Added for better code readability
     *
     * @param  string  $role
     * @param  string|null  $compareAgainst
     *
     * @return bool
     */
    public static function insufficient(string $role, ?string $compareAgainst = null)
    {
        return !self::sufficient($role, $compareAgainst);
    }
}
