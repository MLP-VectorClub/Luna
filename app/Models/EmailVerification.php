<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending e-mail address verification, shared with Winterchilla (`email_verifications` table)
 */
class EmailVerification extends Model
{
    public const VALID_HOURS = 2;
    public const RESEND_MINUTES = 10;

    protected $fillable = ['user_id', 'email', 'hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expiresAt(): CarbonInterface
    {
        return ($this->created_at ?? now())->copy()->addHours(self::VALID_HOURS);
    }

    public function isValid(): bool
    {
        return now()->lessThanOrEqualTo($this->expiresAt());
    }

    /**
     * Link to the verification page of the front end, which posts the hash back to the API
     */
    public function link(bool $block = false): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/users/verify?'.http_build_query(['hash' => $this->hash, 'action' => $block ? 'block' : 'verify']);
    }
}
