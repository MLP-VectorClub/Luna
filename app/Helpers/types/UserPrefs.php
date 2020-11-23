<?php

use OpenApi\Annotations as OA;

# Generated OpenAPI annotations for the darkaonline/l5-swagger package
# This file should not be edited directly, generate it using `php artisan generate-enum-docs`"

/**
 * @OA\Schema(
 *   schema="UserPrefs",
 *   type="object",
 *   description="A list of preferences for the current user (or defaults if not signed in)",
 *   required=USER_PREF_KEYS,
 *   additionalProperties=false,
 *   @OA\Property(
 *     property="cg_itemsperpage",
 *     type="number",
 *   ),
 *   @OA\Property(
 *     property="cg_hidesynon",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="cg_hideclrinfo",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="cg_fulllstprev",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="cg_nutshell",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="cg_defaultguide",
 *     nullable=true,
 *     allOf={
 *       @OA\Schema(ref="#/components/schemas/GuideName")
 *     },
 *   ),
 *   @OA\Property(
 *     property="p_avatarprov",
 *     ref="#/components/schemas/AvatarProvider",
 *   ),
 *   @OA\Property(
 *     property="p_vectorapp",
 *     nullable=true,
 *     allOf={
 *       @OA\Schema(ref="#/components/schemas/VectorApp")
 *     },
 *   ),
 *   @OA\Property(
 *     property="p_hidediscord",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="p_hidepcg",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="p_homelastep",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="ep_hidesynopses",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="ep_noappprev",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="ep_revstepbtn",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="a_pcgearn",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="a_pcgmake",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="a_pcgsprite",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="a_postreq",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="a_postres",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="a_reserve",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="pcg_slots",
 *     type="number",
 *     nullable=true,
 *   ),
 * )
 */
