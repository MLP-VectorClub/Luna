<?php

namespace App\Utils;

use App\Models\TwoFactor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** Asking a signed in user to prove who they are again before a change to how they sign in */
class IdentityCheck
{
    private const MAX_FAILURES = 10;
    private const DECAY_SECONDS = 900;

    /**
     * The current password when the account has one, else a code of its authenticator app (or a recovery code) when it has one. An account with
     * neither has only the signed in session to go by. Throws a validation error on `currentPassword` / `code`, 429 after too many wrong answers
     */
    public static function confirm(Request $request, User $user): void
    {
        $key = "identity:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            abort(429, 'Too many wrong answers, try again later', ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        if ($user->password !== null) {
            $current = $request->input('currentPassword');
            if (!is_string($current) || $current === '') {
                throw ValidationException::withMessages(['currentPassword' => 'The current password is required']);
            }
            if (!Hash::check($current, $user->password)) {
                RateLimiter::hit($key, self::DECAY_SECONDS);
                throw ValidationException::withMessages(['currentPassword' => 'The current password is incorrect']);
            }

            return;
        }

        $factor = TwoFactor::where('user_id', $user->id)->whereNotNull('confirmed_at')->first();
        if ($factor === null) {
            return;
        }
        $code = $request->input('code');
        if (!is_string($code) || $code === '') {
            throw ValidationException::withMessages(['code' => 'A code of your authenticator app (or a recovery code) is required']);
        }
        if (!TwoFactorAuth::verifyCode($factor, $code) && !TwoFactorAuth::useRecoveryCode($user, $code)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            throw ValidationException::withMessages(['code' => 'That code is not right']);
        }
    }

    /** After a change that weakens or replaces a way of signing in: everywhere else is signed out, the browser or token that asked stays */
    public static function endOtherSessions(Request $request, User $user): void
    {
        if (config('session.driver') === 'database') {
            $sessions = DB::table(config('session.table'))->where('user_id', $user->id);
            if ($request->hasSession()) {
                $sessions->where('id', '!=', $request->session()->getId());
            }
            $sessions->delete();
        }

        $current = $user->currentAccessToken();
        $user->tokens()->when(isset($current->id), fn($tokens) => $tokens->where('id', '!=', $current->id))->delete();
    }
}
