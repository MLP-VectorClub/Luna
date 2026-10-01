<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventEntry extends Model
{
    protected $fillable = ['event_id', 'title', 'sub_prov', 'sub_id', 'submitted_by', 'prev_src', 'prev_full', 'prev_thumb'];

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
