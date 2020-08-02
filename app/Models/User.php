<?php

namespace App\Models;

use App\Enums\Role;
use App\Traits\HasProtectedFields;
use App\Utils\SettingsHelper;
use Browser;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use OpenApi\Annotations as OA;

/**
 * @property Role $role
 */
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
    ];

    public function daUser()
    {
        return $this->belongsTo(DeviantartUser::class);
    }

    public function isStaff(): bool
    {
        return perm(Role::Staff(), $this->role);
    }

    public function getAvatarProviderAttribute()
    {
        return 'gravatar';
    }

    public static function any(): bool
    {
        return DB::selectOne('SELECT 1 FROM users LIMIT 1') !== null;
    }

    public function authResponse()
    {
        $token = $this->createToken(sprintf('%s on %s', Browser::browserName(), Browser::platformName()));

        return response()->json(['token' => $token->plainTextToken]);
    }

    public function publicResponse(): array
    {
        $data = $this->toArray();
        if ($data['role'] === 'developer') {
            $data['role'] = SettingsHelper::get('dev_role_label');
        }
        if ($this->avatar_provider === 'gravatar') {
            $data['email_hash'] = md5($this->email);
        }
        return $data;
    }
}
