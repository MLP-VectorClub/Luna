<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = [
        'id',
        'old_id',
        'type',
        'preview',
        'fullsize',
        'label',
        'requested_by',
        'requested_at',
        'reserved_by',
        'reserved_at',
        'deviation_id',
        'lock',
        'finished_at',
        'broken',
        'show_id',
    ];

    public function getCreatedAtColumn(): string
    {
        return $this->requested_by !== null ? 'requested_at' : 'reserved_at';
    }
}
