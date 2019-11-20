<?php

namespace App;

use Browser;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\HasApiTokens;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Cookie;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'display_name', 'email', 'password', 'role', 'avatar_url'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token', 'email_verified_at', 'created_at', 'updated_at'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    protected $appends = [
        'avatar_provider',
    ];

    protected static function boot()
    {
        parent::boot();

        self::creating(function (self $user) {
            $user->display_name = $user->name;
        });
    }

    public function isStaff(): bool
    {
        return perm('staff', $this->role);
    }

    public function getAvatarProviderAttribute()
    {
        return 'deviantart';
    }

    public static function any(): bool
    {
        return DB::selectOne('SELECT 1 FROM users LIMIT 1') !== null;
    }

    public function authResponse()
    {
        $token = $this->createToken(sprintf('%s on %s', Browser::browserName(), Browser::platformName()));

        $config = app('config')->get('session');
        $expiration = Carbon::now()->add(Passport::tokensExpireIn());

        $cookie = new Cookie(
            Passport::cookie(),
            $token->accessToken,
            $expiration,
            $config['path'],
            $config['domain'],
            $config['secure'],
            true,
            false,
            $config['same_site'] ?? null
        );

        return response()->json([
            'access_token' => $token->accessToken,
        ])->withCookie($cookie);
    }
}
