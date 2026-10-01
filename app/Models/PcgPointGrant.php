<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PcgPointGrant extends Model
{
    protected $table = 'pcg_point_grants';

    protected $fillable = ['receiver_id', 'sender_id', 'amount', 'comment'];

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    /**
     * Stores the grant and the matching slot history entry, then refreshes the receiver's slot count
     */
    public static function record(int $receiver_id, int $sender_id, int $amount, ?string $comment = null): self
    {
        return DB::transaction(function () use ($receiver_id, $sender_id, $amount, $comment) {
            $grant = self::create(compact('receiver_id', 'sender_id', 'amount', 'comment'));
            $grant->makeRelatedEntries();

            return $grant;
        });
    }

    public function makeRelatedEntries(bool $sync = true): void
    {
        PcgSlotHistory::record($this->receiver_id, $this->amount > 0 ? 'manual_give' : 'manual_take', abs($this->amount), [
            'comment' => $this->comment,
            'by' => $this->sender_id,
        ], $this->created_at);
        if ($sync) {
            $this->receiver->syncPcgSlotCount();
        }
    }
}
