<?php

namespace App\Models;

use App\Enums\AvatarProvider;
use App\Enums\Role;
use App\Enums\UserPrefKey;
use App\Traits\HasEnumCasts;
use App\Traits\HasProtectedFields;
use App\Utils\Core;
use App\Utils\SettingsHelper;
use App\Utils\UserPrefHelper;
use Browser;
use Creativeorange\Gravatar\Facades\Gravatar;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use OpenApi\Annotations as OA;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, Notifiable, HasProtectedFields;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password', 'role', 'avatar_url'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token', 'email_verified_at', 'created_at', 'updated_at'
    ];

    protected array $protected_fields = ['email'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'role' => Role::class,
    ];

    protected $appends = [
        'avatar_provider',
        'avatar_url',
    ];

    public function daUser()
    {
        return $this->hasOne(DeviantartUser::class);
    }

    public function discordMember()
    {
        return $this->hasOne(DiscordMember::class);
    }

    public function prefs()
    {
        return $this->hasMany(UserPref::class);
    }

    public function postedSHow()
    {
        return $this->hasMany(Show::class, 'posted_by');
    }

    public function sendEmailVerificationNotification()
    {
        if ($this->email === null) {
            return;
        }

        parent::sendEmailVerificationNotification();
    }

    public function isStaff(): bool
    {
        return perm(Role::Staff(), $this->role);
    }

    public function getAvatarProviderAttribute(): AvatarProvider
    {
        return UserPrefHelper::get($this, UserPrefKey::Personal_AvatarProvider());
    }

    public function getAvatarUrlAttribute(): ?string
    {
        switch ($this->avatar_provider) {
            case AvatarProvider::DeviantArt:
                /** @var DeviantartUser $da_user */
                $da_user = $this->daUser()->first();
                if ($da_user === null) {
                    return null;
                }
                return $da_user->avatar_url ?: null;
            case AvatarProvider::Discord:
                /** @var DiscordMember $discord_member */
                $discord_member = $this->discordMember()->first();
                if ($discord_member === null) {
                    return null;
                }
                return $discord_member->avatar_url;
            case AvatarProvider::Gravatar:
                return Gravatar::get($this->email);
        }

        return null;
    }

    public static function any(): bool
    {
        return DB::selectOne('SELECT 1 FROM users LIMIT 1') !== null;
    }

    public function authResponse()
    {
        $token = $this->createToken(Core::getDeviceIdentifier());

        return response()->camelJson(['token' => $token->plainTextToken]);
    }

    public function publicResponse(): array
    {
        $data = $this->toArray();
        if ($data['role'] === Role::Developer) {
            $data['role'] = SettingsHelper::get('dev_role_label');
        }
        return $data;
    }
}
