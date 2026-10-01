<?php

namespace App\Models;

use App\Enums\AvatarProvider;
use App\Enums\Role;
use App\Enums\UserPrefKey;
use App\Traits\HasEnumCasts;
use App\Traits\HasProtectedFields;
use App\Utils\Core;
use App\Utils\LogWriter;
use App\Utils\SettingsHelper;
use App\Utils\UserPrefHelper;
use Browser;
use Creativeorange\Gravatar\Facades\Gravatar;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use OpenApi\Annotations as OA;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, HasProtectedFields;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password', 'role', 'avatar_url'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token', 'email_verified_at', 'created_at', 'updated_at'
    ];

    protected array $protected_fields = ['email'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'role' => Role::class,
    ];

    protected $appends = [
        'avatar_provider',
        'avatar_url',
    ];

    public function daUser()
    {
        return $this->hasOne(DeviantartUser::class);
    }

    public function discordMember()
    {
        return $this->hasOne(DiscordMember::class);
    }

    public function prefs()
    {
        return $this->hasMany(UserPref::class);
    }

    public function postedSHow()
    {
        return $this->hasMany(Show::class, 'posted_by');
    }

    public function sendEmailVerificationNotification()
    {
        if ($this->email === null) {
            return;
        }

        parent::sendEmailVerificationNotification();
    }

    public function isStaff(): bool
    {
        return perm(Role::Staff, $this->role);
    }

    public function getAvatarProviderAttribute(): AvatarProvider
    {
        return UserPrefHelper::get($this, UserPrefKey::Personal_AvatarProvider);
    }

    public function getAvatarUrlAttribute(): ?string
    {
        switch ($this->avatar_provider) {
            case AvatarProvider::DeviantArt:
                /** @var DeviantartUser $da_user */
                $da_user = $this->daUser()->first();
                if ($da_user === null) {
                    return null;
                }
                return $da_user->avatar_url ?: null;
            case AvatarProvider::Discord:
                /** @var DiscordMember $discord_member */
                $discord_member = $this->discordMember()->first();
                if ($discord_member === null) {
                    return null;
                }
                return $discord_member->avatar_url;
            case AvatarProvider::Gravatar:
                return Gravatar::get($this->email);
        }

        return null;
    }

    public static function any(): bool
    {
        return DB::selectOne('SELECT 1 FROM users LIMIT 1') !== null;
    }

    public function authResponse()
    {
        $token = $this->createToken(Core::getDeviceIdentifier());

        return response()->camelJson(['token' => $token->plainTextToken]);
    }

    public function publicResponse(): array
    {
        $data = $this->toArray();
        if ($data['role'] === Role::Developer) {
            $data['role'] = SettingsHelper::get('dev_role_label');
        }
        return $data;
    }

    /**
     * Stores the sum of the slot history as the `pcg_slots` preference (what the personal guide checks against)
     */
    public function syncPcgSlotCount(): void
    {
        UserPrefHelper::set($this, UserPrefKey::Pcg_Slots, PcgSlotHistory::sumFor($this->id));
    }

    /**
     * Personal guide points the user can still spend. The history is derived data, so it is built the first time it is needed.
     */
    public function pcgAvailablePoints(): int
    {
        if (UserPrefHelper::get($this, UserPrefKey::Pcg_Slots) === null) {
            $this->recalculatePcgSlotHistory();
        }

        return (int) UserPrefHelper::get($this, UserPrefKey::Pcg_Slots);
    }

    /**
     * Rebuilds the slot history from scratch: the free trial, approved finished requests, existing appearances and manual grants
     */
    public function recalculatePcgSlotHistory(): void
    {
        DB::transaction(function () {
            PcgSlotHistory::where('user_id', $this->id)->delete();

            // Free slot for everyone, the feature was introduced on 2017-12-16
            PcgSlotHistory::record($this->id, 'free_trial', null, null, max(Carbon::parse('2017-12-16T13:36:59Z'), $this->created_at));

            // Points for approved requests fulfilled for somebody else
            $approved = DB::table('posts')->whereNotNull('requested_by')->where('requested_by', '!=', $this->id)->whereNotNull('deviation_id')
                ->where('reserved_by', $this->id)->where('lock', true)->orderBy('finished_at')->get(['id']);
            foreach ($approved as $post) {
                $locked_at = DB::table('locked_posts')->where('post_id', $post->id)->value('created_at');
                PcgSlotHistory::record($this->id, 'post_approved', null, ['id' => $post->id], $locked_at);
            }

            // Slots taken by the appearances that exist
            foreach (Appearance::where('owner_id', $this->id)->orderBy('id')->get() as $appearance) {
                PcgSlotHistory::record($this->id, 'appearance_add', null, ['id' => $appearance->id, 'label' => $appearance->label], $appearance->created_at);
            }

            foreach (PcgPointGrant::where('receiver_id', $this->id)->orderBy('id')->get() as $grant) {
                $grant->makeRelatedEntries(false);
            }

            $this->syncPcgSlotCount();
        });
    }

    /**
     * Changes the role and records it like Winterchilla does. A developer's role is never stored differently:
     * changing it changes the public label of the developer role instead.
     */
    public function updateRole(Role $new_role): void
    {
        $old_role = $this->role;
        if ($old_role === Role::Developer) {
            $old_label = SettingsHelper::get('dev_role_label');
            if ($old_label === $new_role->value) {
                return;
            }
            SettingsHelper::set('dev_role_label', $new_role->value);
            $old_role_value = $old_label;
        } else {
            $this->role = $new_role;
            $this->save();
            $old_role_value = $old_role->value;
        }

        LogWriter::record('rolechange', ['target' => $this->id, 'oldrole' => $old_role_value, 'newrole' => $new_role->value]);

        $was_staff = perm(Role::Staff, Role::from($old_role_value));
        $is_staff = perm(Role::Staff, $new_role);
        if ($was_staff !== $is_staff) {
            PcgSlotHistory::record($this->id, $is_staff ? 'staff_join' : 'staff_leave');
            $this->syncPcgSlotCount();
        }
    }
}
