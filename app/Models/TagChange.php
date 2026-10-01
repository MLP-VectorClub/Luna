<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TagChange extends Model
{
    protected $fillable = ['tag_id', 'appearance_id', 'user_id', 'added', 'tag_name'];

    protected $casts = ['added' => 'boolean'];
}
