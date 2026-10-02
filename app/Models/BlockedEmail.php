<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Addresses that asked not to receive verification e-mails (shared with Winterchilla's `blocked_emails` table)
 */
class BlockedEmail extends Model
{
    protected $fillable = ['email'];

    public static function record(string $email): void
    {
        self::firstOrCreate(['email' => strtolower($email)]);
    }

    public static function isBlocked(string $email): bool
    {
        return self::where('email', strtolower($email))->exists();
    }
}
