<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\ResetPassword;
use App\Models\BlockedEmail;
use App\Models\PasswordReset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Throwable;
use Valorin\Pwned\Pwned;

/**
 * Forgotten passwords: a link goes to the account's e-mail address, and following it sets a new password. Reading the mailbox is all it proves, so it
 * changes nothing else: two-step verification stays on, and every session and token of the account ends
 */
class PasswordResetController extends Controller
{
    #[OA\Post(
        path: '/users/password/forgot',
        description: 'Sends a link for setting a new password to the address of an account. The answer is the same whether or not an account uses the address, so it can\'t be used to find out who is a member',
        tags: ['authentication'],
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['email'],
                additionalProperties: false,
                properties: [new OA\Property(property: 'email', type: 'string')],
            ),
        ),
        responses: [
            new OA\Response(
                response: '202',
                description: 'Accepted',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['message'],
                    additionalProperties: false,
                    properties: [new OA\Property(property: 'message', type: 'string')],
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'No address',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function forgot(Request $request): JsonResponse
    {
        $input = $request->validate(['email' => 'required|string|max:128']);
        $answer = response()->json(['message' => 'If an account uses that e-mail address, we have sent it a link for choosing a new password. It works for ' . PasswordReset::VALID_MINUTES . ' minutes.'], 202);

        // Opportunistic clean-up, a link is useless after an hour
        PasswordReset::where('created_at', '<', now()->subDay())->delete();

        $limiter = 'password-reset:' . hash('sha256', mb_strtolower(trim($input['email'])));
        if (RateLimiter::tooManyAttempts($limiter, PasswordReset::MAILS_PER_HOUR)) {
            return $answer;
        }

        $user = User::whereEmail($input['email'])->first();
        if ($user === null || $user->email === null) {
            // Unknown addresses use up tries too, otherwise the limit would tell them from members
            RateLimiter::hit($limiter, 3600);

            return $answer;
        }
        RateLimiter::hit($limiter, 3600);

        if (BlockedEmail::isBlocked($user->email)) {
            return $answer;
        }

        // After the answer has gone out: sending a mail takes a while, which would tell members from strangers
        $id = $user->id;
        $name = $user->name;
        $address = $user->email;
        defer(function () use ($id, $name, $address) {
            try {
                Mail::to($address)->send(new ResetPassword($name, PasswordReset::issue(User::findOrFail($id))));
            } catch (Throwable $e) {
                PasswordReset::where('user_id', $id)->delete();
                Log::error("Failed to send password reset email: {$e->getMessage()}");
            }
        });

        return $answer;
    }

    #[OA\Post(
        path: '/users/password/reset',
        description: 'Sets a new password with the token of the link that `POST /users/password/forgot` sent. Ends every session and token of the account',
        tags: ['authentication'],
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['token', 'newPassword'],
                additionalProperties: false,
                properties: [
                    new OA\Property(property: 'token', type: 'string', minLength: 64, maxLength: 64),
                    new OA\Property(property: 'newPassword', type: 'string', minLength: 8, maxLength: 300),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Changed',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['message'],
                    additionalProperties: false,
                    properties: [new OA\Property(property: 'message', type: 'string')],
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'The link is not valid (any more), or the password is not acceptable',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function reset(Request $request): JsonResponse
    {
        $input = $request->validate([
            'token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'newPassword' => ['required', 'string', 'min:8', 'max:300', new Pwned],
        ], [
            'token.regex' => 'This link is not valid',
            'newPassword.required' => 'The new password is required',
            'newPassword.min' => 'The new password must be between 8 and 300 characters long',
            'newPassword.max' => 'The new password must be between 8 and 300 characters long',
        ]);

        $reset = PasswordReset::findValid($input['token']);
        $user = $reset === null ? null : User::find($reset->user_id);
        if ($user === null) {
            throw ValidationException::withMessages(['token' => 'This link has expired or was already used, please ask for a new one']);
        }

        DB::transaction(function () use ($user, $input) {
            $user->forceFill(['password' => Hash::make($input['newPassword'])])->save();
            PasswordReset::where('user_id', $user->id)->delete();
            $user->tokens()->delete();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table'))->where('user_id', $user->id)->delete();
            }
        });

        return response()->json(['message' => 'Your new password has been set. You have been signed out everywhere, so you will need to sign in again.']);
    }
}
