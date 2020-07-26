<?php

namespace App\Models;

use App\Traits\Sorted;
use Illuminate\Database\Eloquent\Model;

class Cutiemark extends Model
{
    protected $fillable = [
        'appearance_id',
        'facing',
        'favme',
        'rotation',
        'contributor_id',
        'label',
    ];

    public function appearance()
    {
        return $this->belongsTo(Appearance::class);
    }

    public function contributor()
    {
        return $this->belongsTo(DeviantartUser::class, 'contributor_id');
    }
}
