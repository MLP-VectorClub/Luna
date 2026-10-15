<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $credential_id base64url
 * @property string $record serialized `Webauthn\CredentialRecord`
 * @property string $name
 * @property ?\Carbon\Carbon $last_used_at
 * @property ?\Carbon\Carbon $created_at
 */
class Passkey extends Model
{
    protected $table = 'luna_passkeys';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['record'];
    protected $casts = ['last_used_at' => 'datetime', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
