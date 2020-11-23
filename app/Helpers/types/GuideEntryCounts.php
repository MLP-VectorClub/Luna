<?php

use OpenApi\Annotations as OA;

# Generated OpenAPI annotations for the darkaonline/l5-swagger package
# This file should not be edited directly, generate it using `php artisan generate-enum-docs`"

/**
 * @OA\Schema(
 *   schema="GuideEntryCounts",
 *   type="object",
 *   description="An object containing the number of entries in each color guide",
 *   required=GUIDE_NAMES,
 *   additionalProperties=false,
 *   @OA\Property(
 *     property="pony",
 *     type="number",
 *     example=122,
 *     minimum=0
 *   ),
 *   @OA\Property(
 *     property="eqg",
 *     type="number",
 *     example=248,
 *     minimum=0
 *   ),
 *   @OA\Property(
 *     property="pl",
 *     type="number",
 *     example=14,
 *     minimum=0
 *   ),
 * )
 */
