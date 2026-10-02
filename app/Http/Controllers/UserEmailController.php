<?php

namespace App\Http\Controllers;

use App\Mail\VerifyEmailAddress;
use App\Models\BlockedEmail;
use App\Models\EmailVerification;
use App\Models\User;
use App\Rules\StrictEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Changing the e-mail address of an account: a verification e-mail is sent to the new address, the link in it takes the user to the front end which
 * posts the hash back. Ported from Winterchilla's UserAPIController::emailApi() / verifyApi() and Users::sendEmailValidation()
 */
class UserEmailController extends Controller
{
    /**
     * @OA\Post(
     *   path="/users/{id}/email-changes",
     *   operationId="PostUsersIdEmailChanges",
     *   description="Requests an e-mail address change (or resend of a pending verification e-mail) for the specified user. Requires staff permission. When changing the address of the requester's own account, the current password must be set and verified first.",
     *   tags={"users"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object",
     *     @OA\Property(property="resend", type="boolean", description="If true, resends the existing pending verification e-mail instead of requesting a new address change"),
     *     @OA\Property(property="newEmail", type="string", minLength=3, maxLength=128, description="The new e-mail address to verify, required unless 'resend' is true"),
     *     @OA\Property(property="currentPassword", type="string", description="The user's current password, required when changing their own e-mail address")
     *   )),
     *   @OA\Response(response="200", description="A confirmation e-mail has been sent", @OA\JsonContent(type="object", required={"message"}, @OA\Property(property="message", type="string"))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permission (staff required)", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="User not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="409", description="A password must be set before the e-mail address can be changed", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Invalid, unchanged, blocked or already used e-mail address, or a missing or incorrect current password", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse")),
     *   @OA\Response(response="429", description="A confirmation e-mail was sent to this address recently", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="503", description="The confirmation e-mail could not be sent", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function request(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $same_user = $request->user()->id === $user->id;
        $resend = filter_var($request->input('resend', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($resend === null) {
            $this->fail('resend', 'The resend value is invalid');
        }

        $new_email = null;
        if (!$resend) {
            $new_email = $request->input('newEmail');
            if (!is_string($new_email) || $new_email === '') {
                $this->fail('newEmail', 'The new e-mail is required');
            }
            if (mb_strlen($new_email) < 3 || mb_strlen($new_email) > 128) {
                $this->fail('newEmail', 'The new e-mail must be between 3 and 128 characters long');
            }
            if ($user->email !== null && strcasecmp($new_email, $user->email) === 0) {
                $this->fail('newEmail', 'You are trying to use same e-mail address '.($same_user ? 'you' : 'this user').' already '.($same_user ? 'have' : 'has').' set');
            }
            if (!(new StrictEmail())->passes('newEmail', $new_email)) {
                $this->fail('newEmail', 'The provided e-mail address does not pass our validity checks, please use an e-mail address which is properly set up to receive messages.');
            }

            if ($same_user) {
                if ($user->password === null) {
                    throw new HttpException(409, 'You will need to set a password first before changing your e-mail address');
                }
                $current = $request->input('currentPassword');
                if (!is_string($current) || $current === '') {
                    $this->fail('currentPassword', 'The current password is required');
                }
                if (!Hash::check($current, $user->password)) {
                    $this->fail('currentPassword', 'The current password is incorrect');
                }
            }

            if (User::where('email', $new_email)->exists()) {
                $this->fail('newEmail', 'This e-mail address is already in use by another user');
            }
        }

        $recipient = $new_email ?? $user->email;
        if ($recipient === null) {
            $this->fail('newEmail', 'There is no e-mail address to send the confirmation to');
        }
        if (BlockedEmail::isBlocked($recipient)) {
            $this->fail('newEmail', 'The specified email address has been added to our do-not-send list. If you are the owner of this address and would like to be removed from this list, please contact us.');
        }
        $recent = EmailVerification::where('email', $recipient)->where('created_at', '>', now()->subMinutes(EmailVerification::RESEND_MINUTES))->exists();
        if ($recent) {
            throw new HttpException(429, 'A confirmation email was sent to this address recently, please wait a bit before requesting another one');
        }

        $verification = EmailVerification::create(['user_id' => $user->id, 'email' => $recipient, 'hash' => bin2hex(random_bytes(64))]);
        try {
            Mail::to($recipient)->send(new VerifyEmailAddress($verification));
        } catch (Throwable $e) {
            $verification->delete();
            Log::error("Failed to send verification email: {$e->getMessage()}");
            throw new HttpException(503, 'There was an issue while trying to send a confirmation e-mail, please try again later');
        }

        return response()->json(['message' => 'A confirmation e-mail has been sent to the specified address with a link to verify your address. Click the link to update the address in your account.']);
    }

    /**
     * @OA\Post(
     *   path="/users/email/verify",
     *   operationId="PostUsersEmailVerify",
     *   description="Verifies or blocks an e-mail address based on a verification hash sent to that address. Requires staff permission.",
     *   tags={"users"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"hash", "action"},
     *     @OA\Property(property="hash", type="string", minLength=128, maxLength=128, description="The verification hash sent to the e-mail address"),
     *     @OA\Property(property="action", type="string", enum={"verify", "block"}, description="Whether to verify the e-mail address for the account, or add it to the do-not-send list")
     *   )),
     *   @OA\Response(response="200", description="The e-mail address was successfully verified or blocked", @OA\JsonContent(type="object", required={"message"}, @OA\Property(property="message", type="string"))),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permission (staff required)", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="The verification hash or action is invalid, or the hash has expired", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function verify(Request $request): JsonResponse
    {
        $hash = $request->input('hash');
        if (!is_string($hash) || $hash === '') {
            $this->fail('hash', 'The hash value is missing');
        }
        if (strlen($hash) !== 128) {
            $this->fail('hash', 'The hash value must be exactly 128 characters long');
        }
        $verification = EmailVerification::where('hash', $hash)->first();
        if ($verification === null || !$verification->isValid()) {
            $this->fail('hash', 'The specified validation hash is either invalid or has expired');
        }

        $action = $request->input('action');
        if (!is_string($action) || $action === '') {
            $this->fail('action', 'The action value is missing');
        }
        if (!in_array($action, ['verify', 'block'], true)) {
            $this->fail('action', 'The action value is invalid');
        }

        if ($action === 'block') {
            BlockedEmail::record($verification->email);

            return response()->json(['message' => 'Your e-mail address has been added to our do-not-send list successfully.']);
        }

        DB::transaction(function () use ($verification) {
            $verification->user->forceFill(['email' => $verification->email, 'email_verified_at' => now()])->save();
            $verification->delete();
        });

        return response()->json(['message' => 'Your e-mail address has been verified successfully.']);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
