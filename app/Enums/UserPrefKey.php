<?php

namespace App\Enums;

use App\Utils\EnumWrapper;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="UserPrefKeys",
 *     type="string",
 *     description="List of available user preferences",
 *     enum=USER_PREF_KEYS,
 *     example="cg_itemsperpage"
 * )
 * @method static self ColorGuide_ItemsPerPage()
 * @method static self ColorGuide_HideSynonymTags()
 * @method static self ColorGuide_HideColorInfo()
 * @method static self ColorGuide_HideFullListPreviews()
 * @method static self ColorGuide_NutshellNames()
 * @method static self ColorGuide_DefaultGuide()
 * @method static self Personal_AvatarProvider()
 * @method static self Personal_VectorApp()
 * @method static self Personal_HideDiscord()
 * @method static self Personal_PrivatePersonalGuide()
 * @method static self Personal_HomeLastEpisode()
 * @method static self Episode_HydeSynopses()
 * @method static self Episode_NoAppearancePreviews()
 * @method static self Episode_ReverseStepButtons()
 * @method static self Admin_CanEarnPcgPoints()
 * @method static self Admin_CanMakePcgAppearances()
 * @method static self Admin_CanUploadPcgSprites()
 * @method static self Admin_CanPostRequests()
 * @method static self Admin_CanPostReservations()
 * @method static self Admin_CanReservePosts()
 * @method static self Pcg_Slots()
 */
final class UserPrefKey extends EnumWrapper
{
    protected static function values(): array
    {
        return [
            'ColorGuide_ItemsPerPage' => 'cg_itemsperpage',
            'ColorGuide_HideSynonymTags' => 'cg_hidesynon',
            'ColorGuide_HideColorInfo' => 'cg_hideclrinfo',
            'ColorGuide_HideFullListPreviews' => 'cg_fulllstprev',
            'ColorGuide_NutshellNames' => 'cg_nutshell',
            'ColorGuide_DefaultGuide' => 'cg_defaultguide',
            'Personal_AvatarProvider' => 'p_avatarprov',
            'Personal_VectorApp' => 'p_vectorapp',
            'Personal_HideDiscord' => 'p_hidediscord',
            'Personal_PrivatePersonalGuide' => 'p_hidepcg',
            'Personal_HomeLastEpisode' => 'p_homelastep',
            'Episode_HydeSynopses' => 'ep_hidesynopses',
            'Episode_NoAppearancePreviews' => 'ep_noappprev',
            'Episode_ReverseStepButtons' => 'ep_revstepbtn',
            'Admin_CanEarnPcgPoints' => 'a_pcgearn',
            'Admin_CanMakePcgAppearances' => 'a_pcgmake',
            'Admin_CanUploadPcgSprites' => 'a_pcgsprite',
            'Admin_CanPostRequests' => 'a_postreq',
            'Admin_CanPostReservations' => 'a_postres',
            'Admin_CanReservePosts' => 'a_reserve',
            'Pcg_Slots' => 'pcg_slots',
        ];
    }
}
