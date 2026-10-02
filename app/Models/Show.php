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

    /**
     * Path of the show on the front end, same format as Winterchilla (`/episode/S1E1-Title`, `/movie/5-Title`)
     */
    public function toUrl(): string
    {
        if ($this->isEpisode()) {
            $episode = $this->parts === 2 ? "{$this->episode}-".($this->episode + 1) : $this->episode;
            $url = "/episode/S{$this->season}E$episode";
        } else {
            $url = "/{$this->type}/{$this->id}";
        }
        if (!empty($this->title)) {
            $url .= '-'.trim(preg_replace('/-+/', '-', preg_replace('/[^A-Za-z\d\-]/', '-', $this->title)), '-');
        }

        return $url;
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
