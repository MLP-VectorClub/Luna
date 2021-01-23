<?php

namespace App\Models;

use App\Enums\MlpGeneration;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Show extends Model
{
    use HasFactory;

    protected $table = 'show';

    protected $fillable = [
        'type',
        'season',
        'episode',
        'parts',
        'title',
        'posted_by',
        'airs',
        'no',
        'score',
        'notes',
        'synopsys_last_checked',
        'generation',
    ];

    protected $casts = [
        'airs' => 'datetime',
        'synopsys_last_checked' => 'datetime',
        'generation' => MlpGeneration::class,
    ];

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
