<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    protected $fillable = ['entry_type', 'initiator', 'ip', 'data'];

    protected $casts = ['data' => 'array'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'initiator');
    }
}
