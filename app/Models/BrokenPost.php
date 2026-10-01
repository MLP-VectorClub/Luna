<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrokenPost extends Model
{
    protected $fillable = ['post_id', 'reserved_by', 'response_code', 'failing_url'];
}
