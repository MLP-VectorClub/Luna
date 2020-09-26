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
 *     example=250,
 *     minimum=0
 *   ),
 *   @OA\Property(
 *     property="eqg",
 *     type="number",
 *     example=36,
 *     minimum=0
 *   ),
 *   @OA\Property(
 *     property="pl",
 *     type="number",
 *     example=2,
 *     minimum=0
 *   ),
 * )
 */
