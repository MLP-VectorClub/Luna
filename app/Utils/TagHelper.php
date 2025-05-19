<?php

namespace App\Utils;

use App\Models\Tag;
use App\UserPrefs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TagHelper
{
    /**
     * Retrieve set of tags for a given appearance
     *
     * @param  int  $appearance_id
     * @param  bool  $synonyms
     * @param  bool  $exporting
     * @return Collection
     */
    public static function getFor($appearance_id, ?bool $synonyms = null, bool $exporting = false)
    {
        if ($synonyms === null) {
            $synonyms = $exporting; // || !UserPrefs::get('cg_hidesynon');
        }

        $tag_query = Tag::query();
        if (!$synonyms) {
            $tag_query = $tag_query
                ->whereNull('synonym_of')
                ->join('tagged', 'tagged.tag_id', '=', 'tags.id');
        } else {
            $tag_query = $tag_query
                ->join('tagged', function (JoinClause $join) {
                    $join->on('tagged.tag_id', '=', 'tags.id')
                         ->orWhereRaw('tagged.tag_id = tags.synonym_of');
                });// 'tagged.tag_id', 'IN', DB::raw('(tags.id, tags.synonym_of)'));
        }

        $tag_query = $tag_query->where('tagged.appearance_id', $appearance_id);

        return self::get($tag_query, null, $exporting);
    }

    /**
     * Retrieve set of tags for a given appearance
     *
     * @param  Builder|null  $tag_query
     * @param  LengthAwarePaginator|null  $limit
     * @param  bool  $exporting
     * @return Collection
     */
    public static function get(
        ?Builder $tag_query = null,
        ?LengthAwarePaginator $limit = null,
        $exporting = false
    ) {
        if ($tag_query === null) {
            $tag_query = Tag::query();
        }

        if ($exporting) {
            $tag_query = $tag_query->orderBy('tags.id');
        } else {
            $tag_query = $tag_query
                ->orderByLiteral('CASE WHEN tags.type IS NULL THEN 1 ELSE 0 END')
                ->orderBy('tags.type')
                ->orderBy('tags.name');
        }

        if ($limit) {
            $tag_query->forPage($limit->currentPage(), $limit->perPage());
        }

        return $tag_query->get('tags.*');
    }
}
