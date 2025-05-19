<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DiscordMember extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'username',
        'discriminator',
        'nick',
        'avatar_hash',
        'joined_at',
        'access',
        'refresh',
        'scope',
        'expires',
        'last_synced',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getAvatarUrlAttribute()
    {
        if (empty($this->avatar_hash)) {
            return 'https://cdn.discordapp.com/embed/avatars/'.($this->discriminator % 5).'.png';
        }

        $ext = Str::startsWith($this->avatar_hash, "a_") ? 'gif' : 'png';

        return "https://cdn.discordapp.com/avatars/{$this->id}/{$this->avatar_hash}.$ext";
    }
}
