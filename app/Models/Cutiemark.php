<?php

namespace App\Models;

use App\Interfaces\HasStoredFiles;
use App\Traits\Sorted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Cutiemark extends Model implements HasStoredFiles
{
    protected $fillable = [
        'appearance_id',
        'facing',
        'favme',
        'rotation',
        'contributor_id',
        'label',
    ];

    public function appearance(): BelongsTo
    {
        return $this->belongsTo(Appearance::class);
    }

    public function contributor(): BelongsTo
    {
        return $this->belongsTo(DeviantartUser::class, 'contributor_id');
    }

    public function vectorFile(): MorphOne
    {
        return $this->morphOne(UserUpload::class, 'fileable');
    }

    public function getRelativeOutputPath(): string
    {
        return 'cutiemarks';
    }
}
