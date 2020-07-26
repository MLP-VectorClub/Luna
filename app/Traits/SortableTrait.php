<?php

namespace App\Traits;

use Illuminate\Database\Query\Builder;
use Spatie\EloquentSortable\SortableTrait as OriginSortableTrait;

/**
 * @method static Builder ordered()
 */
trait SortableTrait
{
    use OriginSortableTrait;

    public $sortable = ['order_column_name' => 'order'];
}
