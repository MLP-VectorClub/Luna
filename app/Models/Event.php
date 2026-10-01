<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = ['name', 'starts_at', 'ends_at', 'max_entries', 'entry_role', 'vote_role', 'desc_src', 'desc_rend', 'added_by', 'result_favme', 'finalized_at', 'finalized_by'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'finalized_at' => 'datetime'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function entries()
    {
        return $this->hasMany(EventEntry::class)->orderBy('created_at')->orderBy('id');
    }

    public function toContract(): array
    {
        $iso = fn(?CarbonInterface $time) => $time?->toIso8601String();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'startsAt' => $iso($this->starts_at),
            'endsAt' => $iso($this->ends_at),
            'maxEntries' => $this->max_entries,
            'entryRole' => $this->entry_role,
            'voteRole' => $this->vote_role,
            'resultFavMe' => $this->result_favme,
            'finalizedAt' => $iso($this->finalized_at),
        ];
    }
}
