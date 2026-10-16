<?php

namespace App\Models;

use App\Enums\BlendMode;
use App\Traits\Sorted;
use Illuminate\Database\Eloquent\Model;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Color extends Model implements Sortable
{
    // The table has no created_at / updated_at columns
    public $timestamps = false;

    use SortableTrait;

    // Order is assigned per appearance / color group by the controllers, not globally
    public $sortable = ['sort_when_creating' => false];

    protected $fillable = [
        'group_id',
        'order',
        'label',
        'hex',
        'opacity',
        'blend_mode',
    ];

    protected function casts(): array
    {
        return ['opacity' => 'integer'];
    }

    /** Blend mode of the color when it is semi-transparent, normal unless something says otherwise */
    public function blendMode(): BlendMode
    {
        return BlendMode::tryFrom((string) $this->blend_mode) ?? BlendMode::Normal;
    }

    public function colorGroup()
    {
        return $this->belongsTo(ColorGroup::class, 'group_id');
    }
}
