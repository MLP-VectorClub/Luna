<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    protected $fillable = ['message_html', 'type', 'hide_after', 'show_after', 'site', 'posted_by'];

    protected $casts = [
        'hide_after' => 'datetime',
        'show_after' => 'datetime',
    ];

    public const TYPES = ['info', 'success', 'fail', 'warn', 'caution'];

    /** Which site shows a notice: both of them, or only one while they share a database */
    public const SITES = ['all', 'celestia', 'winterchilla'];

    /** Notices this site shows right now: started (or not scheduled), not hidden yet and meant for it */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('hide_after', '>', now())
            ->where(fn(Builder $q) => $q->whereNull('show_after')->orWhere('show_after', '<=', now()))
            ->whereIn('site', ['all', 'celestia']);
    }

    public function toContract(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'messageHtml' => $this->message_html,
            'showAfter' => $this->show_after?->toIso8601String(),
            'hideAfter' => $this->hide_after->toIso8601String(),
            'site' => $this->site,
            'postedBy' => $this->posted_by,
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
