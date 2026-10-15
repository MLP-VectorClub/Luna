<?php

namespace App\Utils;

use App\Models\RecoveryCode;
use App\Models\TwoFactor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** The authenticator app (TOTP) and recovery codes of an account */
class TwoFactorAuth
{
    public const RECOVERY_CODE_COUNT = 10;

    private static function google(): Google2FA
    {
        return new Google2FA();
    }

    public static function newSecret(): string
    {
        return self::google()->generateSecretKey(32);
    }

    /** What an authenticator app scans */
    public static function uri(User $user, string $secret): string
    {
        $issuer = config('app.name') ?: 'MLP Vector Club';

        return self::google()->getQRCodeUrl($issuer, $user->name, $secret);
    }

    /**
     * Checks a six digit code against the secret of the account: one step either side of now is accepted, and a step is accepted once (a code that was
     * used cannot be used again, even within its 30 seconds). Marks the step as used when the code is good
     */
    public static function verifyCode(TwoFactor $factor, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $step = self::google()->verifyKeyNewer($factor->secret, $code, $factor->last_used_step ?? 0, 1);
        // The library answers `true` instead of the step when it was given no earlier one, hence the 0 above
        if (!is_int($step)) {
            return false;
        }

        // An atomic claim, two requests with the same code must not both pass
        $claimed = TwoFactor::where('user_id', $factor->user_id)
            ->where(fn($q) => $q->whereNull('last_used_step')->orWhere('last_used_step', '<', $step))
            ->update(['last_used_step' => $step]);
        if ($claimed !== 1) {
            return false;
        }
        $factor->last_used_step = $step;

        return true;
    }

    /** @return string[] ten new codes in the form `abcde-fghij`, shown once (only their hashes are kept) */
    public static function replaceRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = Str::lower(Str::random(5)) . '-' . Str::lower(Str::random(5));
        }

        DB::transaction(function () use ($user, $codes) {
            RecoveryCode::where('user_id', $user->id)->delete();
            foreach ($codes as $code) {
                RecoveryCode::create(['user_id' => $user->id, 'code_hash' => self::hashRecoveryCode($code)]);
            }
        });

        return $codes;
    }

    private static function hashRecoveryCode(string $code): string
    {
        $normalized = Str::lower(preg_replace('/[^a-z0-9]/i', '', $code));

        return hash_hmac('sha256', $normalized, config('app.key'));
    }

    /** Uses up a recovery code, false when it does not exist or was used already */
    public static function useRecoveryCode(User $user, string $code): bool
    {
        return RecoveryCode::where('user_id', $user->id)
                ->where('code_hash', self::hashRecoveryCode($code))
                ->whereNull('used_at')
                ->update(['used_at' => now()]) === 1;
    }

    public static function remainingRecoveryCodes(User $user): int
    {
        return RecoveryCode::where('user_id', $user->id)->whereNull('used_at')->count();
    }
}
