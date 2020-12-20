<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PinnedAppearance extends Model
{
    use HasFactory;

    protected $fillable = [
        'guide',
        'appearance_id'
    ];

    public function appearance()
    {
        return $this->hasOne(Appearance::class);
    }
}
