<?php

namespace App\Services\ApiKeys;

use App\Enums\ApiKeyScope;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

class ApiKeyService
{
    public const TOKEN_PREFIX = 'vck_';
    public const MAX_KEYS_PER_USER = 25;
    /** Days a key stays valid for, null for no expiry */
    public const EXPIRY_DAYS = [30, 90, 365];

    /**
     * @param string[] $scopes values of {@see ApiKeyScope}
     */
    public function create(User $user, string $name, array $scopes, ?int $expiryDays): NewAccessToken
    {
        return DB::transaction(function () use ($user, $name, $scopes, $expiryDays) {
            // Serialize concurrent creations of the same user so the limit holds
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($this->listFor($user)->count() >= self::MAX_KEYS_PER_USER) {
                throw ValidationException::withMessages(['name' => 'You have reached the maximum number of API keys']);
            }

            return $this->issue($user, $name, $scopes, $expiryDays === null ? null : now()->addDays($expiryDays));
        });
    }

    /** @return Collection<int, PersonalAccessToken> */
    public function listFor(User $user): Collection
    {
        return PersonalAccessToken::query()->apiKeys()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->orderBy('id')
            ->get();
    }

    public function find(User $user, int $id): ?PersonalAccessToken
    {
        return $this->listFor($user)->firstWhere('id', $id);
    }

    public function revoke(PersonalAccessToken $key): void
    {
        $key->delete();
    }

    /** Replace a key with a new secret that keeps the name, scopes and lifespan of the old one, which stops working */
    public function rotate(User $user, int $id): ?NewAccessToken
    {
        return DB::transaction(function () use ($user, $id) {
            $old = PersonalAccessToken::query()->apiKeys()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->getKey())
                ->whereKey($id)->lockForUpdate()->first();
            if ($old === null) {
                return null;
            }

            $expires = $old->expires_at === null ? null : now()->addSeconds($old->created_at->diffInSeconds($old->expires_at, true));
            $new = $this->issue($user, $old->name, $old->abilities ?? [], $expires);
            $old->delete();

            return $new;
        });
    }

    /** @param string[] $scopes */
    private function issue(User $user, string $name, array $scopes, $expiresAt): NewAccessToken
    {
        // The prefix is global config in Sanctum, device tokens must stay unprefixed
        $previous = config('sanctum.token_prefix');
        config(['sanctum.token_prefix' => self::TOKEN_PREFIX]);
        try {
            $token = $user->createToken($name, $scopes, $expiresAt);
        } finally {
            config(['sanctum.token_prefix' => $previous]);
        }
        $token->accessToken->forceFill(['is_api_key' => true])->save();

        return $token;
    }
}
