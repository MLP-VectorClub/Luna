<?php

declare(strict_types=1);

namespace App\Utils;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use OpenApi\Annotations as OA;
use RuntimeException;
use function array_key_exists;

class Permission
{
    protected static function getRoleLevel(Role $role): int {
        static $value_map;

        if (!$value_map) {
            $value_map = [
                Role::User()->value => 1,
                Role::Member()->value => 2,
                Role::Assistant()->value => 3,
                Role::Staff()->value => 3,
                Role::Admin()->value => 3,
                Role::Developer()->value => 255,
            ];
        }

        if (!isset($value_map[$role->value])) {
            throw new \RuntimeException("Missing value for role {$role->value}");
        }

        return $value_map[$role->value];
    }

    /**
     * Permission checking function
     * ----------------------------
     * Compares the currently logged in user's role to the one specified
     * A "true" return value means that the user meets the required role or surpasses it.
     * If user isn't logged in, and $compareAgainst is missing, returns false
     * If $compareAgainst is set then $role is used as the current user's role
     *
     * @param  Role      $role
     * @param  Role|null $compareAgainst
     *
     * @return bool
     */
    public static function sufficient(Role $role, ?Role $compareAgainst = null): bool
    {
        if (!Role::hasValue($role)) {
            throw new RuntimeException("Invalid role: $role");
        }

        $comparison = $compareAgainst !== null;

        if ($comparison) {
            $check_role = $compareAgainst;
        } else {
            /** @var User $user */
            $user = Auth::user();
            if (!$user) {
                return false;
            }
            $check_role = $user->role;
        }

        return self::getRoleLevel($check_role) >= self::getRoleLevel($role);
    }

    /**
     * Same as above, except the return value is inverted
     * Added for better code readability
     *
     * @param  Role  $role
     * @param  Role|null  $compare_against
     *
     * @return bool
     */
    public static function insufficient(Role $role, ?Role $compare_against = null)
    {
        return !self::sufficient($role, $compare_against);
    }
}
