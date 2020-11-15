<?php

namespace App\Enums;

use BenSampo\Enum\Enum;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="FullGuideSortField",
 *   type="string",
 *   description="List of possible sorting options for the full guide page",
 *   enum=GUIDE_SORT_FIELDS,
 *   default="relevance",
 *   example="label"
 * )
 */
final class FullGuideSortField extends Enum
{
    const Alphabetically = 'label';
    const DateAdded = 'added';
    const Relevance = 'relevance';
}
