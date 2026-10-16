<?php

namespace App\Helpers\types;

use OpenApi\Attributes as OA;

# Generated OpenAPI attributes for the darkaonline/l5-swagger package
# This file should not be edited directly, generate it using `php artisan generate-enum-docs`"

#[OA\Schema(
    schema: 'GuideEntryCounts',
    type: 'object',
    description: 'An object containing the number of entries in each color guide',
    required: \GUIDE_NAMES,
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'pony', type: 'number', example: 53, minimum: 0),
        new OA\Property(property: 'eqg', type: 'number', example: 246, minimum: 0),
    ],
)]
class GuideEntryCounts {}
