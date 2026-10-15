<?php

namespace App\Utils;

use App\Enums\AvatarProvider;
use App\Enums\Role;
use App\Enums\SocialProvider;
use App\Enums\UserPrefKey;
use App\Http\Requests\SocialAuthRequest;
use App\Models\DeviantartUser;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Models\DiscordMember;
use App\Models\PreviousUsername;
use App\Models\User;
use App\Rules\StrictEmail;
use App\Rules\UniqueEmail;
use App\Rules\Username;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Valorin\Pwned\Pwned;

class AccountHelper
{
    /**
     * @param  array  $data  = [
     *     'id' => int,
     *     'role' => new Role,
     *     'name' => string,
     *     'email' => string,
     *     'password' => string,
     * ]
     * @return User
     */
    public static function create($data): User
    {
        // Names are unique, and the one of a DeviantArt account may be held by somebody else already
        if (isset($data['name']) && User::where('name', $data['name'])->exists()) {
            abort(409, 'That name is already used by another account on this site, please contact the staff to sort it out.');
        }

        // The first user of a fresh installation is the developer
        if (!User::any()) {
            $data['role'] = Role::Developer;
        }

        // Hash password
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        return User::create($data);
    }

    public static function isSanctum(Request $request): bool
    {
        return $request->attributes->get('sanctum') === true;
    }

    public static function authResponse(Request $request, User $user, bool $remember = true)
    {
        if (self::isSanctum($request)) {
            Auth::login($user, true);
            return response()->noContent();
        }

        return $user->authResponse();
    }

    /**
     * @param  \Laravel\Socialite\Contracts\User  $data
     * @param  string  $expires_key
     * @return array $tokens = [
     *     'access' => string,
     *     'refresh' => string,
     *     'expires' => new \Illuminate\Support\Carbon,
     * ]
     */
    public static function tokenResponseToModelData(
        \Laravel\Socialite\Contracts\User $data,
        string $expires_key = 'expires'
    ): array {
        /**
         * @var array $tokens = [
         *     'access_token' => string,
         *     'refresh_token' => string,
         *     'expires_in' => int,
         * ]
         */
        $tokens = $data->accessTokenResponseBody;

        return [
            'access' => $tokens['access_token'],
            'refresh' => $tokens['refresh_token'],
            $expires_key => now()->addSeconds($tokens['expires_in']),
        ];
    }

    /**
     * @param  \Laravel\Socialite\Contracts\User  $data
     * @return User
     * @throws \Exception
     * @see https://socialiteproviders.com/Deviantart/#installation-basic-usage
     */
    public static function socialDeviantart(\Laravel\Socialite\Contracts\User $data): User
    {
        $record = DeviantartUser::find($data->getId());
        if ($record === null) {
            // A DeviantArt account that signs in for the first time gets an account here: that is the only way to sign up
            $app_user = self::create([
                'name' => $data->getNickname(),
                'role' => Role::User,
            ]);
            UserPrefHelper::set($app_user, UserPrefKey::Personal_AvatarProvider, AvatarProvider::DeviantArt);

            $record = new DeviantartUser();
            $record->id = $data->getId();
            $record->user_id = $app_user->id;
        } else {
            $app_user = $record->user()->first();
        }

        $new_name = $data->getNickname();
        $old_names = $record->exists ? array_unique(array_filter([$app_user->name, $record->name])) : [];

        $record->name = $new_name;
        $record->avatar_url = $data->getAvatar();
        foreach (self::tokenResponseToModelData($data, 'access_expires') as $k => $v) {
            $record->setAttribute($k, $v);
        }
        $record->save();

        // Renamed on DeviantArt: the user's name follows and the old names are remembered, so profiles can still be found by them
        if (strcasecmp($app_user->name, $new_name) !== 0 || $old_names !== []) {
            foreach ($old_names as $old_name) {
                if (strcasecmp($old_name, $new_name) !== 0) {
                    PreviousUsername::record($record->id, $old_name);
                }
            }
            // Names are unique, if somebody else took the new one the account keeps the name it has
            if (strcasecmp($app_user->name, $new_name) !== 0 && !User::where('name', $new_name)->where('id', '!=', $app_user->id)->exists()) {
                $app_user->name = $new_name;
                $app_user->save();
            }
        }

        return $app_user;
    }

    /**
     * @param  \Laravel\Socialite\Contracts\User  $data
     * @return User
     * @throws \Exception
     * @see https://socialiteproviders.com/Deviantart/#installation-basic-usage
     */
    public static function socialDiscord(\Laravel\Socialite\Contracts\User $data): User
    {
        // Discord only signs in to accounts that exist (linked in the account settings, or bound by Winterchilla): accounts are made through DeviantArt
        $record = DiscordMember::find($data->getId());
        $app_user = $record?->user()->first();
        if ($app_user === null) {
            Log::notice('OAuth sign-in: no local account for the Discord user', ['id' => $data->getId(), 'name' => $data->getName()]);
            abort(404, 'Could not find local account for user');
        }

        /**
         * @var array $raw_data = [
         *     'id' => string,
         *     'username' => string,
         *     'discriminator' => number,
         *     'avatar' => string,
         * ]
         */
        $raw_data = $data->getRaw();
        $record->username = $data['username'];
        $record->discriminator = $data['discriminator'];
        $record->avatar_hash = $raw_data['avatar'];
        foreach (self::tokenResponseToModelData($data) as $k => $v) {
            $record->setAttribute($k, $v);
        }
        $record->save();

        return $app_user;
    }

    private static function createRedirectUrl(string $provider): string {
        return sprintf("%s/oauth/%s", config('app.frontend_url'), $provider);
    }

    /** Name of the cookie that carries the PKCE code verifier from the redirect to the code exchange */
    public const PKCE_COOKIE = 'oauth_pkce';

    private const PKCE_LIFETIME_MINUTES = 10;

    /**
     * Exchanges the code the provider sent back for the provider's user, with the redirect URL and PKCE verifier of the redirect that started it
     *
     * @throws ValidationException when the provider refuses the code
     */
    public static function socialUser(Request $request, string $provider): \Laravel\Socialite\Contracts\User
    {
        $driver = Socialite::driver($provider)->stateless();
        $driver->redirectUrl(self::createRedirectUrl($provider));
        self::pkceSession(self::readPkceCookie($request));
        try {
            return $driver->user();
        } catch (ClientException $e) {
            // The provider refused the code: it was already used (a reload of the callback page sends it again), expired, or the redirect URL differs
            Log::warning("OAuth sign-in with {$provider} was refused by the provider", [
                'status' => $e->getResponse()->getStatusCode(),
                'body' => substr((string) $e->getResponse()->getBody(), 0, 300),
            ]);
            throw ValidationException::withMessages(['code' => 'The authorization code was rejected, it may have expired or already been used. Please try again.']);
        }
    }

    /**
     * Socialite keeps the PKCE verifier in the request's session, which stateless requests (a popup opened by the front end, an
     * API client) do not have. Gives the current request a throwaway in-memory session so Socialite can work, the verifier
     * travels in an encrypted cookie instead (see `socialRedirect` and `socialAuth`).
     */
    private static function pkceSession(?string $verifier = null): Store
    {
        $store = new Store('oauth-pkce', new ArraySessionHandler(self::PKCE_LIFETIME_MINUTES));
        if ($verifier !== null) {
            $store->put('code_verifier', $verifier);
        }
        request()->setLaravelSession($store);

        return $store;
    }

    private static function readPkceCookie(Request $request): ?string
    {
        $value = $request->cookies->get(self::PKCE_COOKIE);
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    public static function socialRedirect(SocialAuthRequest $request)
    {
        $validated = $request->validated();
        $driver = Socialite::driver($validated['provider'])->stateless();
        switch ($validated['provider']) {
            case SocialProvider::DeviantArt:
                $driver->setScopes('user browse');
                break;
            case SocialProvider::Discord:
                $driver->setScopes(['identify', 'email']);
                break;
        }
        $driver->redirectUrl(self::createRedirectUrl($validated['provider']));
        $session = self::pkceSession();
        $response = $driver->redirect();
        $verifier = $session->get('code_verifier');
        if ($verifier !== null) {
            $response->headers->setCookie(new Cookie(
                self::PKCE_COOKIE,
                Crypt::encryptString($verifier),
                now()->addMinutes(self::PKCE_LIFETIME_MINUTES),
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                Cookie::SAMESITE_LAX
            ));
        }

        return $response;
    }

    public static function socialAuth(SocialAuthRequest $request)
    {
        $validated = $request->validated();
        $data = self::socialUser($request, $validated['provider']);

        DB::transaction(function () use ($validated, $data, &$user) {
            switch ($validated['provider']) {
                case SocialProvider::DeviantArt->value:
                    $user = self::socialDeviantart($data);
                    break;
                case SocialProvider::Discord->value:
                    $user = self::socialDiscord($data);
                    break;
                default:
                    throw new RuntimeException("Unhandled provider {$validated['provider']}");
            }
        });

        return self::authResponse($request, $user);
    }
}
