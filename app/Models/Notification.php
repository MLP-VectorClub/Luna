<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = ['recipient_id', 'type', 'data', 'read_at'];

    protected $casts = ['data' => 'array', 'read_at' => 'datetime'];

    public const TYPES = ['post-finished', 'post-approved'];

    /**
     * Notifies a user. An earlier unread notification about the same post is marked as read first.
     */
    public static function send(int $recipient_id, string $type, array $data): self
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \RuntimeException("Invalid notification type: $type");
        }

        self::where('recipient_id', $recipient_id)->where('type', $type)->whereNull('read_at')
            ->whereRaw("data->>'id' = ?", [(string) ($data['id'] ?? '')])
            ->update(['read_at' => now()]);

        return self::create(['recipient_id' => $recipient_id, 'type' => $type, 'data' => $data]);
    }
}
