<?php

namespace App\Utils;

use App\Enums\AvatarProvider;
use App\Enums\Role;
use App\Enums\SocialProvider;
use App\Enums\UserPrefKey;
use App\Http\Requests\SocialAuthRequest;
use App\Models\DeviantartUser;
use App\Models\DiscordMember;
use App\Models\User;
use App\Rules\StrictEmail;
use App\Rules\Username;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;
use Valorin\Pwned\Pwned;

class AccountHelper
{
    public static function validator(array $data)
    {
        return Validator::make($data, [
            'name' => [
                'required',
                'string',
                'min:5',
                'max:20',
                'unique:users',
                new Username(),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'min:3',
                'unique:users',
                new StrictEmail(),
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:300',
                new Pwned,
            ],
        ]);
    }

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
        $have_users = User::any();
        // TODO remove when registration for the public is open
        if ($have_users) {
            abort(503, 'New registrations are currently not accepted, thank you for your understanding.');
        }

        // First user will receive developer role
        if (!$have_users) {
            $data['role'] = Role::Developer();
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

    public static function authResponse(Request $request, User $user, bool $register, bool $remember = true)
    {
        if ($register) {
            event(new Registered($user));
        }

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
     * @param  bool  $register
     * @return User
     * @throws \Exception
     * @see https://socialiteproviders.com/Deviantart/#installation-basic-usage
     */
    public static function socialDeviantart(\Laravel\Socialite\Contracts\User $data, bool $register): User
    {
        $record = DeviantartUser::find($data->getId());
        if ($record === null) {
            if (!$register) {
                abort(404, 'Could not find local account for user');
            }
            $app_user = self::create([
                'name' => $data->getNickname(),
                'role' => Role::User(),
            ]);
            UserPrefHelper::set($app_user, UserPrefKey::Personal_AvatarProvider(), AvatarProvider::DeviantArt());

            $record = new DeviantartUser();
            $record->id = $data->getId();
        } else {
            $app_user = $record->user()->first();
        }

        $record->name = $data->getNickname();
        $record->avatar_url = $data->getAvatar();
        foreach (self::tokenResponseToModelData($data, 'access_expires') as $k => $v) {
            $record->setAttribute($k, $v);
        }
        $record->save();

        return $app_user;
    }

    /**
     * @param  \Laravel\Socialite\Contracts\User  $data
     * @param  bool  $register
     * @return User
     * @throws \Exception
     * @see https://socialiteproviders.com/Deviantart/#installation-basic-usage
     */
    public static function socialDiscord(\Laravel\Socialite\Contracts\User $data, bool $register): User
    {
        $record = DiscordMember::find($data->getId());
        if ($record === null) {
            if (!$register) {
                abort(404, 'Could not find local account for user');
            }
            $app_user = self::create([
                'name' => $data->getName(),
                'email' => $data->getEmail(),
                'role' => Role::User(),
            ]);
            UserPrefHelper::set($app_user, UserPrefKey::Personal_AvatarProvider(), AvatarProvider::Discord());

            $record = new DiscordMember();
            $record->id = $data->getId();
        } else {
            $app_user = $record->user()->first();
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

    public static function socialRedirect(SocialAuthRequest $request, bool $register)
    {
        $validated = $request->validated();
        $driver = Socialite::driver($validated['provider'])->stateless();
        switch ($validated['provider']) {
            case SocialProvider::DeviantArt():
                $driver->setScopes('user browse');
                break;
            case SocialProvider::Discord():
                $driver->setScopes(['identify', 'email']);
                break;
        }
        // TODO Handle on frontend
        $register_param = $register ? '?register=true' : '';
        $driver->redirectUrl(sprintf("%s/oauth/%s", config('app.frontend_url'), $validated['provider']) . $register_param);
        return $driver->redirect();
    }

    public static function socialAuth(SocialAuthRequest $request, bool $register)
    {
        $validated = $request->validated();
        $data = Socialite::driver($validated['provider'])->stateless()->user();

        DB::transaction(function () use ($validated, $data, $register, &$user) {
            switch ($validated['provider']) {
                case SocialProvider::DeviantArt():
                    $user = self::socialDeviantart($data, $register);
                    break;
                case SocialProvider::Discord():
                    $user = self::socialDiscord($data, $register);
                    break;
                default:
                    throw new RuntimeException("Unhandled provider {$validated['provider']}");
            }
        });

        return self::authResponse($request, $user, $register);
    }
}
