<?php

namespace App\Models;

use App\Models\Appearance;
use App\Traits\Sorted;
use Illuminate\Database\Eloquent\Model;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Color extends Model implements Sortable
{
    use SortableTrait;

    protected $fillable = [
        'group_id',
        'order',
        'label',
        'hex'
    ];

    public function colorGroup()
    {
        return $this->belongsTo(ColorGroup::class, 'group_id');
    }
}
