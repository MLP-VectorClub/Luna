<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tag extends Model
{
    protected $fillable = [
        'name',
        'title',
        'type',
        'uses',
        'synonym_of',
    ];

    public function synonym(): HasOne
    {
        return $this->hasOne(__CLASS__, 'synonym_of');
    }

    public function appearances()
    {
        return $this->belongsToMany(Appearance::class, 'tagged');
    }
}
