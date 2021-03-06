<?php

namespace App\Models;

use App\Enums\GuideName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MajorChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'appearance_id',
        'reason',
        'user_id',
    ];

    public function appearance(): BelongsTo
    {
        return $this->belongsTo(Appearance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }


    public static function totalForGuide(GuideName $guide): int
    {
        return self::join('appearances', 'major_changes.appearance_id', '=', 'appearances.id')
            ->where('appearances.guide', $guide)
            ->count('major_changes.id');
    }
}
