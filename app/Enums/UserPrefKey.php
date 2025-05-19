<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="UserPrefKeys",
 *     type="string",
 *     description="List of available user preferences",
 *     example="cg_itemsperpage"
 * )
 */
enum UserPrefKey: string
{
    use ValuableEnum;

    case ColorGuide_ItemsPerPage = 'cg_itemsperpage';
    case ColorGuide_HideSynonymTags = 'cg_hidesynon';
    case ColorGuide_HideColorInfo = 'cg_hideclrinfo';
    case ColorGuide_HideFullListPreviews = 'cg_fulllstprev';
    case ColorGuide_NutshellNames = 'cg_nutshell';
    case ColorGuide_DefaultGuide = 'cg_defaultguide';
    case Personal_AvatarProvider = 'p_avatarprov';
    case Personal_VectorApp = 'p_vectorapp';
    case Personal_HideDiscord = 'p_hidediscord';
    case Personal_PrivatePersonalGuide = 'p_hidepcg';
    case Personal_HomeLastEpisode = 'p_homelastep';
    case Episode_HideSynopses = 'ep_hidesynopses';
    case Episode_NoAppearancePreviews = 'ep_noappprev';
    case Episode_ReverseStepButtons = 'ep_revstepbtn';
    case Admin_CanEarnPcgPoints = 'a_pcgearn';
    case Admin_CanMakePcgAppearances = 'a_pcgmake';
    case Admin_CanUploadPcgSprites = 'a_pcgsprite';
    case Admin_CanPostRequests = 'a_postreq';
    case Admin_CanPostReservations = 'a_postres';
    case Admin_CanReservePosts = 'a_reserve';
    case Pcg_Slots = 'pcg_slots';
}
