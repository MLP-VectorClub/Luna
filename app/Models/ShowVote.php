<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShowVote extends Model
{
    public $timestamps = false;

    protected $fillable = ['show_id', 'user_id', 'vote'];
}
