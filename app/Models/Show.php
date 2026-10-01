<?php

namespace App\Models;

use Carbon\CarbonInterface;
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
    ];

    protected $casts = [
        'airs' => 'datetime',
    ];

    public function appearances()
    {
        return $this->belongsToMany(Appearance::class, 'show_appearances');
    }

    public function votes()
    {
        return $this->hasMany(ShowVote::class);
    }

    public function isEpisode(): bool
    {
        return $this->type === 'episode';
    }

    /**
     * When the show counts as aired (air time plus running time), voting opens after that
     */
    public function willHaveAiredBy(): CarbonInterface
    {
        $minutes = $this->isEpisode() ? ($this->parts ?: 1) * 30 : 120;

        return $this->airs->copy()->addMinutes($minutes);
    }

    public function hasAired(): bool
    {
        return $this->willHaveAiredBy()->isPast();
    }

    public function updateScore(): void
    {
        $this->forceFill(['score' => $this->votes()->avg('vote') ?? 0])->save();
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
