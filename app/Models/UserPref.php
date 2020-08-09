<?php

namespace App\Models;

use App\Enums\UserPrefKey;
use App\Traits\HasEnumCasts;
use Illuminate\Database\Eloquent\Model;

class UserPref extends Model
{
    use HasEnumCasts;

    protected $fillable = ['user_id', 'key', 'value'];

    protected $casts = [
        'key' => UserPrefKey::class,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
