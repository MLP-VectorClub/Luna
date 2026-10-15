<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property ?\Carbon\Carbon $used_at
 */
class RecoveryCode extends Model
{
    protected $table = 'luna_recovery_codes';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['used_at' => 'datetime'];
}
