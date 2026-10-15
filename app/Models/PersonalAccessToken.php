<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum stores when a token was last used on every request that carries it, which is a write while the site is read-only
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public function save(array $options = [])
    {
        if (config('app.read_only') && !$this->wasRecentlyCreated && $this->exists && array_keys($this->getDirty()) === ['last_used_at']) {
            return true;
        }

        return parent::save($options);
    }
}
