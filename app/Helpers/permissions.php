<?php

declare(strict_types=1);

use App\Utils\Permission;

if (!function_exists('perm')) {
    function perm(string $required_role, ?string $user_role = null): bool
    {
        return Permission::sufficient($required_role, $user_role);
    }
}
