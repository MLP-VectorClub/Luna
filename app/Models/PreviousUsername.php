<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Names a DeviantArt user had before they renamed their account (shared with Winterchilla's `previous_usernames` table, `user_id` is the DeviantArt id)
 */
class PreviousUsername extends Model
{
    public $timestamps = false;

    protected $fillable = ['username', 'user_id'];

    public static function record(string $deviantart_id, string $username): void
    {
        // The table is unique by name in practice, a name that was recorded for anyone is not recorded again (same as Winterchilla)
        if (self::where('username', $username)->exists()) {
            return;
        }
        self::create(['username' => $username, 'user_id' => $deviantart_id]);
    }
}
