<?php

namespace App\Enums;

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
enum FullGuideSortField: string
{
    use ValuableEnum;

    case Alphabetically = 'label';
    case DateAdded = 'added';
    case Relevance = 'relevance';
}
