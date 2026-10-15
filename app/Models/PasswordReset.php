<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A link that sets a new password for a user. The link carries a random token, only its hash is stored (a copy of the table reveals no working link)
 *
 * @property int $user_id
 * @property string $token_hash
 * @property ?\Carbon\Carbon $created_at
 */
class PasswordReset extends Model
{
    public const VALID_MINUTES = 60;
    /** Requests for the same address are answered the same, but only this many send a mail per hour */
    public const MAILS_PER_HOUR = 3;

    protected $table = 'luna_password_resets';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Replaces the user's link with a new one, returns the token that goes into the mail */
    public static function issue(User $user): string
    {
        $token = Str::lower(bin2hex(random_bytes(32)));
        self::updateOrCreate(['user_id' => $user->id], ['token_hash' => self::hashOf($token), 'created_at' => now()]);

        return $token;
    }

    /** The reset a token belongs to, null when there is none or it has run out */
    public static function findValid(string $token): ?self
    {
        $reset = self::where('token_hash', self::hashOf($token))->first();

        return $reset !== null && $reset->isValid() ? $reset : null;
    }

    public function expiresAt(): CarbonInterface
    {
        return ($this->created_at ?? now())->copy()->addMinutes(self::VALID_MINUTES);
    }

    public function isValid(): bool
    {
        return now()->lessThanOrEqualTo($this->expiresAt());
    }

    public static function link(string $token): string
    {
        return rtrim((string)config('app.frontend_url'), '/') . '/users/reset-password?' . http_build_query(['token' => $token]);
    }
}
