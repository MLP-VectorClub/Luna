<?php

namespace App\Utils;

use App\Models\Appearance;
use App\Models\PinnedAppearance;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the color guide search table (`appearance_search`, shared with Winterchilla) in sync: one `tsvector` per official
 * appearance, made of its label, its tags and their synonyms. Personal guide appearances are never searched.
 *
 * Words are split on everything that is not a letter or a digit and compared lowercase (the `simple` configuration, no stemming),
 * a search term matches every word that starts with it, and all terms have to match
 */
class AppearanceIndex
{
    public static function update(Appearance $appearance): void
    {
        if ($appearance->owner_id !== null) {
            return;
        }

        DB::statement(
            /** @lang PostgreSQL */ "INSERT INTO appearance_search (appearance_id, doc) VALUES (?, to_tsvector('simple', ?))
                ON CONFLICT (appearance_id) DO UPDATE SET doc = EXCLUDED.doc",
            [$appearance->id, self::text($appearance)],
        );
    }

    public static function remove(Appearance $appearance): void
    {
        DB::table('appearance_search')->where('appearance_id', $appearance->id)->delete();
    }

    /**
     * Empties and refills the whole table from every official, unpinned appearance
     *
     * @return int Number of indexed appearances
     */
    public static function rebuild(): int
    {
        return DB::transaction(function () {
            DB::table('appearance_search')->delete();

            $count = 0;
            $pinned = PinnedAppearance::pluck('appearance_id');
            Appearance::whereNull('owner_id')->whereNotIn('id', $pinned)->chunkById(100, function ($appearances) use (&$count) {
                $rows = [];
                $bindings = [];
                foreach ($appearances as $appearance) {
                    $rows[] = "(?, to_tsvector('simple', ?))";
                    array_push($bindings, $appearance->id, self::text($appearance));
                }
                DB::statement(/** @lang PostgreSQL */ 'INSERT INTO appearance_search (appearance_id, doc) VALUES '.implode(', ', $rows), $bindings);
                $count += count($appearances);
            });

            return $count;
        });
    }

    /** What an appearance is found by: its label, its tags and their synonyms */
    public static function text(Appearance $appearance): string
    {
        $tags = $appearance->tags()->whereNull('synonym_of')->get();
        $synonyms = Tag::whereIn('synonym_of', $tags->pluck('id'))->get();

        return implode(' ', self::words($tags->pluck('name')->merge($synonyms->pluck('name'))->prepend($appearance->label)->implode(' ')));
    }

    /** The words of a text, lowercase, as the search table holds them */
    public static function words(string $text): array
    {
        return preg_split('~[^\p{L}\p{N}]+~u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
