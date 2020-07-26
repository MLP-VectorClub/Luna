<?php

namespace App\Utils;

use Illuminate\Pagination\LengthAwarePaginator;
use OpenApi\Annotations as OA;
use function gettype;

class Core
{
    /**
     * @OA\Schema(
     *   schema="PageData",
     *   required={
     *     "pagination"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="pagination",
     *     type="object",
     *     required={
     *       "currentPage",
     *       "totalPages",
     *       "totalItems",
     *       "itemsPerPage"
     *     },
     *     additionalProperties=false,
     *     @OA\Property(
     *       property="currentPage",
     *       type="integer",
     *       minimum=1
     *     ),
     *     @OA\Property(
     *       property="totalPages",
     *       type="integer",
     *       minimum=1
     *     ),
     *     @OA\Property(
     *       property="totalItems",
     *       type="integer",
     *       minimum=0
     *     ),
     *     @OA\Property(
     *       property="itemsPerPage",
     *       type="integer",
     *       minimum=1
     *     ),
     *   ),
     * )
     * @param  LengthAwarePaginator  $paginator
     * @return array
     */
    public static function mapPagination(LengthAwarePaginator $paginator): array
    {
        return [
            'currentPage' => $paginator->currentPage(),
            'totalPages' => $paginator->lastPage(),
            'totalItems' => $paginator->total(),
            'itemsPerPage' => $paginator->perPage(),
        ];
    }


    public static function generateCacheKey(int $version, ...$args)
    {
        $args[] = "v$version";

        return implode('_', array_map(function ($arg) {
            switch (gettype($arg)) {
                case 'boolean':
                    return $arg ? 't' : 'f';
                case 'double':
                case 'float':
                case 'integer':
                    return (string) $arg;
                case 'NULL':
                    return 'null';
                case 'string':
                    return str_replace(' ', '_', $arg);
            }
        }, $args));
    }
}
