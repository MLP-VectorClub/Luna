<?php

namespace App\Models;

use App\Models\Appearance;
use App\Traits\SortableTrait;
use App\Traits\Sorted;
use Illuminate\Database\Eloquent\Model;
use Spatie\EloquentSortable\Sortable;

class ColorGroup extends Model implements Sortable
{
    use SortableTrait;

    protected $fillable = [
        'appearance_id',
        'label',
        'order',
    ];

    public function appearance()
    {
        return $this->belongsTo(Appearance::class);
    }

    public function colors()
    {
        return $this->hasMany(Color::class, 'group_id');
    }
}
