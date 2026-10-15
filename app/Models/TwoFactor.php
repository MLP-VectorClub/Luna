<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The authenticator app (TOTP) of an account. Only a confirmed one is asked for at sign-in
 *
 * @property int $user_id
 * @property string $secret
 * @property ?\Carbon\Carbon $confirmed_at
 * @property ?int $last_used_step
 */
class TwoFactor extends Model
{
    protected $table = 'luna_two_factor';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $guarded = [];
    protected $hidden = ['secret'];
    protected $casts = [
        'secret' => 'encrypted',
        'confirmed_at' => 'datetime',
        'last_used_step' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
