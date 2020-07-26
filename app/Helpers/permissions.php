<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Utils\Permission;

if (!function_exists('perm')) {
    function perm(Role $required_role, ?Role $user_role = null): bool
    {
        return Permission::sufficient($required_role, $user_role);
    }
}
