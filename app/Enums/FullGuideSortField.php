<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="FullGuideSortField",
 *   type="string",
 *   description="List of possible sorting options for the full guide page",
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
