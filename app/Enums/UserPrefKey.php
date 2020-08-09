<?php

namespace App\Enums;

use BenSampo\Enum\Enum;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="UserPrefKeys",
 *     type="string",
 *     description="List of available user preferences",
 *     enum=USER_PREF_KEYS,
 *     example="cg_itemsperpage"
 * )
 */
final class UserPrefKey extends Enum
{
    const ColorGuide_ItemsPerPage = 'cg_itemsperpage';
    const ColorGuide_HideSynonymTags = 'cg_hidesynon';
    const ColorGuide_HideColorInfo = 'cg_hideclrinfo';
    const ColorGuide_HideFullListPreviews = 'cg_fulllstprev';
    const ColorGuide_NutshellNames = 'cg_nutshell';
    const ColorGuide_DefaultGuide = 'cg_defaultguide';
    const Personal_AvatarProvider = 'p_avatarprov';
    const Personal_VectorApp = 'p_vectorapp';
    const Personal_HideDiscord = 'p_hidediscord';
    const Personal_PrivatePersonalGuide = 'p_hidepcg';
    const Personal_HomeLastEpisode = 'p_homelastep';
    const Episode_HideSynopses = 'ep_hidesynopses';
    const Episode_NoAppearancePreviews = 'ep_noappprev';
    const Episode_ReverseStepButtons = 'ep_revstepbtn';
    const Admin_CanEarnPcgPoints = 'a_pcgearn';
    const Admin_CanMakePcgAppearances = 'a_pcgmake';
    const Admin_CanUploadPcgSprites = 'a_pcgsprite';
    const Admin_CanPostRequests = 'a_postreq';
    const Admin_CanPostReservations = 'a_postres';
    const Admin_CanReservePosts = 'a_reserve';
    const Pcg_Slots = 'pcg_slots';
}
