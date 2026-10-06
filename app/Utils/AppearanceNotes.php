<?php

namespace App\Utils;

use App\Enums\GuideName;
use App\Models\Appearance;
use App\Models\Show;

/**
 * Port of Winterchilla's `Appearance::processNotes` (the `render_notes` callback that runs before every save), because the rendered notes are stored
 * in the database both applications read: sanitized HTML, `>>123` as Derpibooru links, `S1E3` style episode IDs (Friendship is Magic only) and
 * `Movie#3` as links to the entry, `#123` as a link to the appearance, `\#` as a literal `#`. The addresses are the ones both front ends serve.
 */
class AppearanceNotes
{
    public const EPISODE_ID_PATTERN = '[sS]0*([0-9])[eE]0*(1\d|2[0-6]|[1-9])(?:-0*(1\d|2[0-6]|[1-9]))?';

    public const MOVIE_ID_PATTERN = '(?:[mM]ovie)#?0*(\d+)';

    public static function render(?string $source, ?GuideName $guide): ?string
    {
        if ($source === null) {
            return null;
        }

        $rendered = HtmlSanitizer::sanitize($source);
        $rendered = preg_replace('/(\s)(&gt;&gt;(\d+))(\D|$)/', "$1<a href='https://derpibooru.org/$3'>$2</a>$4", $rendered);
        if ($guide === GuideName::FriendshipIsMagic) {
            $rendered = preg_replace_callback('/'.self::EPISODE_ID_PATTERN.'/', function (array $match) {
                $episode = self::actualEpisode((int) $match[1], (int) $match[2]);

                return $episode !== null
                    ? "<a href='".self::showUrl($episode)."'>".self::aposEncode(self::escapeHtml($episode->title)).'</a>'
                    : "<strong>{$match[0]}</strong>";
            }, $rendered);
        }
        $rendered = preg_replace_callback('/'.self::MOVIE_ID_PATTERN.'/', function (array $match) {
            $show = Show::find((int) $match[1]);

            return $show !== null
                ? "<a href='".self::showUrl($show)."'>".self::aposEncode(self::shortenTitlePrefix(self::escapeHtml($show->title))).'</a>'
                : "<strong>{$match[0]}</strong>";
        }, $rendered);
        $rendered = preg_replace_callback('/(^|\s(?!\\\\))#(\d+)(\'s?)?\b/', function (array $match) {
            $appearance = Appearance::find((int) $match[2]);

            return $appearance !== null
                ? "{$match[1]}<a href='/cg/v/{$appearance->id}'>{$appearance->label}</a>".(!empty($match[3]) ? self::possessive($appearance->label) : '')
                : (string) $match[0];
        }, $rendered);

        return str_replace('\#', '#', $rendered);
    }

    /** The episode with this number, or the two-part episode that covers it as its second part (Winterchilla's `ShowHelper::getActual`) */
    private static function actualEpisode(int $season, int $episode): ?Show
    {
        if ($season === 0) {
            return null;
        }
        $found = Show::where('season', $season)->where('episode', $episode)->first();
        if ($found !== null) {
            return $found;
        }
        $first_part = Show::where('season', $season)->where('episode', $episode - 1)->first();

        return $first_part !== null && $first_part->parts === 2 ? $first_part : null;
    }

    /** `/episode/S9E25-26-The-Last-Problem`, `/movie/3-Title` (Winterchilla's `Show::toURL`) */
    private static function showUrl(Show $show): string
    {
        if ($show->type === 'episode') {
            $episode = (string) $show->episode;
            if ($show->parts === 2) {
                $episode .= '-'.($show->episode + 1);
            }
            $url = "/episode/S{$show->season}E{$episode}";
        } else {
            $url = "/{$show->type}/{$show->id}";
        }

        return $show->title !== null && $show->title !== '' ? $url.'-'.self::makeUrlSafe($show->title) : $url;
    }

    public static function makeUrlSafe(string $string): string
    {
        return trim(preg_replace('/-+/', '-', preg_replace('/[^A-Za-z\d\-]/', '-', $string)), '-');
    }

    /** `Equestria Girls: Title` becomes `EQG: Title` */
    private static function shortenTitlePrefix(string $title): string
    {
        if (!preg_match('~^\s*(^|.*?[^\\\\]):\s*~', $title, $match) || !isset(ShowHelper::ALLOWED_PREFIXES[$match[1]])) {
            return $title;
        }

        return ShowHelper::ALLOWED_PREFIXES[$match[1]].': '.preg_replace('~^\s*(^|.*?[^\\\\]):\s*~', '', $title);
    }

    /** Winterchilla escaped the title once when formatting it and once more when putting it in the link, `&` becomes `&amp;amp;` there, kept as is */
    private static function escapeHtml(?string $html): string
    {
        return htmlspecialchars($html ?? '', ENT_HTML5);
    }

    private static function aposEncode(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5);
    }

    private static function possessive(string $word): string
    {
        return "'".(mb_substr($word, -1) !== 's' ? 's' : '');
    }
}
