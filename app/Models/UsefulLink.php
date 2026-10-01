<?php

namespace App\Models;

use App\Traits\HasProtectedFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class UsefulLink extends Model implements Sortable
{
    use HasFactory, SortableTrait, HasProtectedFields;

    public $timestamps = false;

    protected $fillable = ['label', 'url', 'title', 'minrole', 'order'];

    protected $protected_fields = ['minrole'];

    // `minrole` is a plain string because it can also be `guest`, which is not a user role
}
