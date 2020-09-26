<?php

namespace App\Models;

use App\Enums\Role;
use App\Traits\HasProtectedFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class UsefulLink extends Model implements Sortable
{
    use HasFactory, SortableTrait, HasProtectedFields;

    protected $fillable = ['label', 'url', 'title', 'minrole', 'order'];

    protected $protected_fields = ['minrole'];

    protected $casts = [
        'minrole' => Role::class,
    ];
}
