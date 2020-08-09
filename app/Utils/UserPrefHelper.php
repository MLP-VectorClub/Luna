<?php


namespace App\Utils;

use App\Enums\AvatarProvider;
use App\Enums\GuideName;
use App\Enums\UserPrefKey;
use App\Enums\VectorApp;
use App\Models\User;
use App\Models\UserPref;
use BenSampo\Enum\Enum;
use BenSampo\Enum\Rules\EnumValue;
use Closure;
use RuntimeException;
use TypeError;
use Validator;

class UserPrefHelper
{
    public static function default(UserPrefKey $key)
    {
        switch ($key) {
            case UserPrefKey::ColorGuide_ItemsPerPage():
            case UserPrefKey::ColorGuide_HideFullListPreviews():
            case UserPrefKey::Admin_CanEarnPcgPoints():
            case UserPrefKey::Admin_CanMakePcgAppearances():
            case UserPrefKey::Admin_CanUploadPcgSprites():
            case UserPrefKey::Admin_CanPostRequests():
            case UserPrefKey::Admin_CanPostReservations():
            case UserPrefKey::Admin_CanReservePosts():
                return true;
            case UserPrefKey::ColorGuide_HideColorInfo():
            case UserPrefKey::ColorGuide_NutshellNames():
            case UserPrefKey::Personal_HideDiscord():
            case UserPrefKey::Personal_PrivatePersonalGuide():
            case UserPrefKey::Personal_HomeLastEpisode():
            case UserPrefKey::Episode_HideSynopses():
            case UserPrefKey::Episode_NoAppearancePreviews():
            case UserPrefKey::Episode_ReverseStepButtons():
                return false;
            case UserPrefKey::Personal_AvatarProvider():
                return AvatarProvider::DeviantArt();
            case UserPrefKey::Personal_VectorApp():
            case UserPrefKey::ColorGuide_DefaultGuide():
            case UserPrefKey::Pcg_Slots():
                return null;

        }

        throw new RuntimeException(sprintf("%s: Unhandled UserPrefKey $key", __METHOD__));
    }

    public static function castRead(UserPrefKey $key, string $value)
    {
        switch ($key) {
            case UserPrefKey::Pcg_Slots():
                return $value === null ? null : self::castInt($value);
            case UserPrefKey::ColorGuide_ItemsPerPage():
                return self::castInt($value);
            case UserPrefKey::ColorGuide_HideColorInfo():
            case UserPrefKey::Admin_CanReservePosts():
            case UserPrefKey::Admin_CanPostReservations():
            case UserPrefKey::Admin_CanPostRequests():
            case UserPrefKey::Admin_CanUploadPcgSprites():
            case UserPrefKey::Admin_CanMakePcgAppearances():
            case UserPrefKey::Admin_CanEarnPcgPoints():
            case UserPrefKey::Episode_ReverseStepButtons():
            case UserPrefKey::Episode_NoAppearancePreviews():
            case UserPrefKey::Episode_HideSynopses():
            case UserPrefKey::Personal_HomeLastEpisode():
            case UserPrefKey::Personal_PrivatePersonalGuide():
            case UserPrefKey::Personal_HideDiscord():
            case UserPrefKey::ColorGuide_NutshellNames():
            case UserPrefKey::ColorGuide_HideFullListPreviews():
            case UserPrefKey::ColorGuide_HideSynonymTags():
                return self::castBool($value);
            case UserPrefKey::Personal_AvatarProvider():
                return self::castEnum($value, AvatarProvider::class);
            case UserPrefKey::Personal_VectorApp():
                return $value === null ? null : self::castEnum($value, VectorApp::class);
            case UserPrefKey::ColorGuide_DefaultGuide():
                return $value === null ? null : self::castEnum($value, AvatarProvider::DeviantArt());
        }

        throw new RuntimeException(sprintf("%s: Unhandled UserPrefKey $key", __METHOD__));
    }

    public static function castWrite(UserPrefKey $key, $value)
    {
        switch ($key) {
            case UserPrefKey::Pcg_Slots():
                /** @var ?int $value */
                return $value === null ? null : (string) $value;
            case UserPrefKey::ColorGuide_ItemsPerPage():
                /** @var int $value */
                return (string) $value;
            case UserPrefKey::ColorGuide_HideColorInfo():
            case UserPrefKey::Admin_CanReservePosts():
            case UserPrefKey::Admin_CanPostReservations():
            case UserPrefKey::Admin_CanPostRequests():
            case UserPrefKey::Admin_CanUploadPcgSprites():
            case UserPrefKey::Admin_CanMakePcgAppearances():
            case UserPrefKey::Admin_CanEarnPcgPoints():
            case UserPrefKey::Episode_ReverseStepButtons():
            case UserPrefKey::Episode_NoAppearancePreviews():
            case UserPrefKey::Episode_HideSynopses():
            case UserPrefKey::Personal_HomeLastEpisode():
            case UserPrefKey::Personal_PrivatePersonalGuide():
            case UserPrefKey::Personal_HideDiscord():
            case UserPrefKey::ColorGuide_NutshellNames():
            case UserPrefKey::ColorGuide_HideFullListPreviews():
            case UserPrefKey::ColorGuide_HideSynonymTags():
                /** @var bool $value */
                return $value ? '1' : '0';
            case UserPrefKey::Personal_AvatarProvider():
                /** @var Enum $value */
                return $value->value;
            case UserPrefKey::Personal_VectorApp():
            case UserPrefKey::ColorGuide_DefaultGuide():
                /** @var null|GuideName|VectorApp $value */
                return $value === null ? null : $value->value;
        }

        throw new RuntimeException(sprintf("%s: Unhandled UserPrefKey $key", __METHOD__));
    }

    public static function validate(UserPrefKey $key, $value)
    {
        switch ($key) {
            case UserPrefKey::Pcg_Slots():
                $rules = ['integer', 'min:0'];
                break;
            case UserPrefKey::ColorGuide_ItemsPerPage():
                $rules = ['required', 'integer', 'min:7', 'max:20'];
                break;
            case UserPrefKey::ColorGuide_HideColorInfo():
            case UserPrefKey::Admin_CanReservePosts():
            case UserPrefKey::Admin_CanPostReservations():
            case UserPrefKey::Admin_CanPostRequests():
            case UserPrefKey::Admin_CanUploadPcgSprites():
            case UserPrefKey::Admin_CanMakePcgAppearances():
            case UserPrefKey::Admin_CanEarnPcgPoints():
            case UserPrefKey::Episode_ReverseStepButtons():
            case UserPrefKey::Episode_NoAppearancePreviews():
            case UserPrefKey::Episode_HideSynopses():
            case UserPrefKey::Personal_HomeLastEpisode():
            case UserPrefKey::Personal_PrivatePersonalGuide():
            case UserPrefKey::Personal_HideDiscord():
            case UserPrefKey::ColorGuide_NutshellNames():
            case UserPrefKey::ColorGuide_HideFullListPreviews():
            case UserPrefKey::ColorGuide_HideSynonymTags():
                $rules = ['required', 'boolean'];
                break;
            case UserPrefKey::Personal_AvatarProvider():
                $rules = ['required', new EnumValue(AvatarProvider::class)];
                break;
            case UserPrefKey::Personal_VectorApp():
                $rules = ['required', new EnumValue(VectorApp::class)];
                break;
            case UserPrefKey::ColorGuide_DefaultGuide():
                $rules = [new EnumValue(ColorGuideHelper::class)];
        }

        if (!isset($rules)) {
            throw new RuntimeException(sprintf("%s: Unhandled UserPrefKey $key", __METHOD__));
        }

        Validator::make(['value' => $value], ['value' => [...$rules]])->validate();
    }

    private static function castBool(string $value): bool
    {
        return $value === '1';
    }

    private static function castInt(string $value): int
    {
        return (int) $value;
    }

    private static function castEnum(string $value, string $class): Enum
    {
        if (!class_exists($class) || !method_exists($class, 'fromValue')) {
            throw new TypeError(sprintf(
                "Argument 2 passed to %s must point to a class that extends %s",
                __METHOD__,
                Enum::class
            ));
        }
        return $class::fromValue($value);
    }

    /**
     * @param  User  $user
     * @param  UserPrefKey  $key
     * @return mixed preference value
     */
    public static function get(User $user, UserPrefKey $key)
    {
        /** @var UserPref $pref */
        $pref = $user->prefs()->where('key', $key)->first();

        if ($pref === null) {
            return self::default($pref->key);
        }

        return self::castRead($pref->key, $pref->value);
    }

    /**
     * @param  User  $user
     * @param  UserPrefKey  $key
     * @param $value
     * @return mixed preference value
     * @throws \Exception
     */
    public static function set(User $user, UserPrefKey $key, $value)
    {
        $default_value = self::default($key);

        /** @var UserPref $pref */
        $pref = $user->prefs()->firstOrCreate(['key' => $key], [
            'value' => $default_value,
        ]);

        self::validate($key, $value);

        if ($value === $default_value) {
            return $pref->delete();
        }

        $db_value = self::castWrite($key, $value);
        return $pref->update(['key' => $key, 'value' => $db_value]);
    }
}
