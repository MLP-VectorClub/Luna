<?php

namespace App\Models;

use App\Models\Appearance;
use App\Traits\Sorted;
use Illuminate\Database\Eloquent\Model;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class ColorGroup extends Model implements Sortable
{
    // The table has no created_at / updated_at columns
    public $timestamps = false;

    use SortableTrait;

    // Order is assigned per appearance / color group by the controllers, not globally
    public $sortable = ['sort_when_creating' => false];

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
