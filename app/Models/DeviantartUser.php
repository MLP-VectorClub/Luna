<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviantartUser extends Model
{
    // The primary key is a UUID
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'avatar_url'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'created_at', 'updated_at'
    ];

    protected $casts = [
        'access_expires' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
