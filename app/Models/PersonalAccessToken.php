<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum stores when a token was last used on every request that carries it, which is a write while the site is read-only
 *
 * @property bool $is_api_key
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_api_key' => 'boolean',
    ];

    public function scopeApiKeys(Builder $query): Builder
    {
        return $query->where('is_api_key', true);
    }

    public function scopeDeviceTokens(Builder $query): Builder
    {
        return $query->where('is_api_key', false);
    }

    public function save(array $options = [])
    {
        if (config('app.read_only') && !$this->wasRecentlyCreated && $this->exists && array_keys($this->getDirty()) === ['last_used_at']) {
            return true;
        }

        return parent::save($options);
    }
}
