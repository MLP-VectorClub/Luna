<?php

namespace App;

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
 * @OA\Schema(
 *     schema="PublicUser",
 *     type="object",
 *     description="Represents an publicly accessible representation of a user",
 *     required={
 *         "id",
 *         "name",
 *         "role",
 *         "avatarUrl",
 *         "avatarProvider",
 *     },
 *     additionalProperties=false,
 *     @OA\Property(
 *         property="id",
 *         type="integer",
 *         minimum=1,
 *         example=1,
 *     ),
 *     @OA\Property(
 *         property="name",
 *         type="string",
 *         example="example",
 *     ),
 *     @OA\Property(
 *         property="role",
 *         description="The publicly visible role for the user",
 *         ref="#/components/schemas/Role",
 *     ),
 *     @OA\Property(
 *         property="avatarUrl",
 *         type="string",
 *         format="uri",
 *         example="https://a.deviantart.net/avatars/e/x/example.png",
 *         nullable=true,
 *     ),
 *     @OA\Property(
 *         property="avatarProvider",
 *         ref="#/components/schemas/AvatarProvider"
 *     ),
 *     @OA\Property(
 *         property="emailHash",
 *         type="string",
 *         description="Hashed version of the e-mail address used in case there is no available avatarUrl to allow loading the Gravatar fallback",
 *         example="e64c7d89f26bd1972efa854d13d7dd61",
 *         format="MD5",
 *     ),
 * )
 * @OA\Schema(
 *     schema="User",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/PublicUser"),
 *         @OA\Schema(
 *             type="object",
 *             description="Represents an authenticated user",
 *             required={
 *                 "name",
 *                 "email",
 *                 "role",
 *             },
 *             additionalProperties=false,
 *             @OA\Property(
 *                 property="name",
 *                 type="string",
 *                 example="example",
 *             ),
 *             @OA\Property(
 *                 property="email",
 *                 type="string",
 *                 example="user@example.com",
 *                 nullable=true,
 *             ),
 *             @OA\Property(
 *                 property="role",
 *                 description="The database-level role for the user",
 *                 ref="#/components/schemas/DatabaseRole",
 *             ),
 *         )
 *     }
 * )
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

    protected array $protected_fields = ['name', 'email'];

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
    }

    public function isStaff(): bool
    {
        return perm('staff', $this->role);
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
