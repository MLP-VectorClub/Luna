<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Addresses that asked not to receive verification e-mails (shared with Winterchilla's `blocked_emails` table)
 */
class BlockedEmail extends Model
{
    protected $fillable = ['email'];

    /** Blocks the address and, like Winterchilla's `after_create` callback, throws away the verifications that are still waiting for it */
    public static function record(string $email): void
    {
        $email = strtolower($email);
        $blocked = self::firstOrCreate(['email' => $email]);
        if ($blocked->wasRecentlyCreated) {
            EmailVerification::whereRaw('lower(email) = ?', [$email])->delete();
        }
    }

    public static function isBlocked(string $email): bool
    {
        return self::where('email', strtolower($email))->exists();
    }
}
