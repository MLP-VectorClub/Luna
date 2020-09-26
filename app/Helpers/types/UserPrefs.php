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
 *     property="cgItemsperpage",
 *     type="number",
 *   ),
 *   @OA\Property(
 *     property="cgHidesynon",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="cgHideclrinfo",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="cgFulllstprev",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="cgNutshell",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="cgDefaultguide",
 *     nullable=true,
 *     allOf={
 *       @OA\Schema(ref="#/components/schemas/GuideName")
 *     },
 *   ),
 *   @OA\Property(
 *     property="pAvatarprov",
 *     ref="#/components/schemas/AvatarProvider",
 *   ),
 *   @OA\Property(
 *     property="pVectorapp",
 *     nullable=true,
 *     allOf={
 *       @OA\Schema(ref="#/components/schemas/VectorApp")
 *     },
 *   ),
 *   @OA\Property(
 *     property="pHidediscord",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="pHidepcg",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="pHomelastep",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="epHidesynopses",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="epNoappprev",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="epRevstepbtn",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="aPcgearn",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="aPcgmake",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="aPcgsprite",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="aPostreq",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="aPostres",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="aReserve",
 *     type="boolean",
 *   ),
 *   @OA\Property(
 *     property="pcgSlots",
 *     type="number",
 *     nullable=true,
 *   ),
 * )
 */
