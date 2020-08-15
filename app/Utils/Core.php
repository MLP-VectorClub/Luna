<?php

namespace App\Utils;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
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

    public static function generateRandomFilename(int $length = 40): string
    {
        return Str::random($length);
    }

    public static function generateHashFilename(string $file_path): string
    {
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);
        return hash_file('sha512', $file_path).'.'.$extension;
    }

    public static function fileToDataUri(string $path): string
    {
        return 'data:image/png;base64,'.base64_encode(file_get_contents($path));
    }

    public static function carbonToIso(?Carbon $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return str_replace('+00:00', 'Z', $date->toW3cString());
    }
}
