<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    protected $fillable = ['message_html', 'type', 'hide_after', 'posted_by'];

    protected $casts = [
        'hide_after' => 'datetime',
    ];

    public const TYPES = ['info', 'success', 'fail', 'warn', 'caution'];

    public function toContract(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'messageHtml' => $this->message_html,
            'hideAfter' => $this->hide_after->toIso8601String(),
            'postedBy' => $this->posted_by,
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
