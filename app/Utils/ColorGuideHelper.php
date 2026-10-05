<?php

namespace App\Utils;

use App\Appearances;
use App\CoreUtils;
use App\DB;
use App\Enums\FullGuideSortField;
use App\Enums\GuideName;
use App\Http\Controllers\ShowController;
use App\Models\Show;
use App\Enums\Role;
use App\Enums\TagType;
use App\Enums\UserPrefKey;
use App\Models\Appearance;
use App\Models\Color;
use App\Models\ColorGroup;
use App\Models\CutieMark;
use App\Models\DeviantartUser;
use App\Models\MajorChange;
use Illuminate\Support\Carbon;
use App\Models\Tag;
use App\Pagination;
use App\ShowHelper;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Elasticsearch;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use function is_array;

class ColorGuideHelper
{
    public static function isElasticAvailable(): bool
    {
        try {
            return Elasticsearch::connection()->ping()->asBool();
        } catch (NoNodeAvailableException|ServerResponseException $e) {
            return false;
        }
    }


    /**
     * @param  int  $page
     * @param  int  $per_page
     * @param  GuideName  $guide
     * @param  string|null  $search_for
     * @return LengthAwarePaginator
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    public static function searchGuide(
        int $page,
        int $per_page,
        GuideName $guide,
        ?string $search_for = null
    ): LengthAwarePaginator {
        $paginator = new LengthAwarePaginator([], 0, $per_page, $page);
        $must = [
            ['term' => ['guide' => $guide->value]],
        ];

        // Search query exists
        if ($search_for !== null) {
            $search_for_sanitized = preg_replace("~[^\w\s*?'-]~", '', $search_for);
            if ($search_for_sanitized !== '') {
                array_unshift($must, [
                    'multi_match' => [
                        'query' => $search_for_sanitized,
                        'fields' => ['label', 'tags'],
                        'type' => 'cross_fields',
                        'minimum_should_match' => '100%',
                    ],
                ]);
            }
        }

        $search_query = [
            'query' => ['bool' => ['must' => $must]],
            'sort' => [['order' => ['order' => 'asc']]],
            '_source' => false,
        ];

        try {
            $search_results = self::searchElastic($search_query, $paginator);
        } catch (ClientResponseException|ServerResponseException $e) {
            $status = $e->getResponse()->getStatusCode();
            $message = $e->getMessage();
            $is_paging_error = ($status === 400 || $e instanceof ServerResponseException)
                && (Str::contains($message, 'Result window is too large, from + size must be less than or equal to')
                    || Str::contains($message, 'Failed to parse int parameter [from] with value'));

            // A missing index or an out-of-range page both just mean "no results"
            if ($status !== 404 && !$is_paging_error) {
                throw $e;
            }

            $search_results = [];
        }

        if (empty($search_results)) {
            return $paginator;
        }

        $total_hits = $search_results['hits']['total'];
        if (is_array($total_hits) && isset($total_hits['value'])) {
            $total_hits = $total_hits['value'];
        }

        $max_pages = ceil($total_hits / $per_page);
        if ($page > $max_pages) {
            return self::searchGuide($max_pages, $per_page, $guide, $search_for);
        }

        if (!empty($search_results['hits']['hits'])) {
            $ids = (new Collection($search_results['hits']['hits']))->map(fn ($el) => $el['_id'])->unique();

            /** @var Appearance[] $appearances */
            $appearances = Appearance::ordered()
                ->whereIn('id', $ids)
                ->where('guide', $guide)
                ->get();
        } else {
            $appearances = [];
        }

        return new LengthAwarePaginator($appearances, $total_hits, $per_page, $page);
    }

    /**
     * Performs an ElasticSearch search operation
     *
     * @param  array  $body
     * @param  LengthAwarePaginator  $paginator
     * @return array
     */
    public static function searchElastic(array $body, LengthAwarePaginator $paginator): array
    {
        $params = [
            'index' => 'appearances',
            'body' => $body,
            'from' => ($paginator->currentPage() - 1) * $paginator->perPage(),
            'size' => $paginator->perPage(),
        ];

        return Elasticsearch::connection()->search($params)->asArray();
    }

    /**
     * @param  GuideName  $guide
     * @return string[]
     */
    private static function getGroupTagIds(GuideName $guide): array
    {
        return match ($guide) {
            GuideName::FriendshipIsMagic => [
                664 => 'Main Cast',
                45 => 'Cutie Mark Crusaders',
                59 => 'Royalty',
                666 => 'Student Six',
                9 => 'Antagonists',
                44 => 'Foals',
                78 => 'Original Characters',
                1 => 'Unicorns',
                3 => 'Pegasi',
                2 => 'Earth Ponies',
                10 => 'Pets',
                437 => 'Non-pony Characters',
                385 => 'Creatures',
                96 => 'Outfits & Clothing',
                // add other tags here
                64 => 'Objects',
                0 => 'Other',
            ],
            GuideName::EquestriaGirls => [
                76 => 'Humans',
                0 => 'Other',
            ],
            default => throw new \RuntimeException("Unhandled guide {$guide->value}"),
        };
    }

    /**
     * @OA\Schema(
     *   schema="GuideFullListGroupItem",
     *   type="object",
     *   required={
     *     "name",
     *     "appearanceIds"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="name",
     *     type="string",
     *     example="Main Cast"
     *   ),
     *   @OA\Property(
     *     property="appearanceIds",
     *     type="array",
     *     @OA\Items(ref="#/components/schemas/OneBasedId")
     *   ),
     * )
     * @OA\Schema(
     *   schema="GuideFullListGroups",
     *   type="object",
     *   required={
     *     "groups",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="groups",
     *     type="array",
     *     minItems=0,
     *     @OA\Items(ref="#/components/schemas/GuideFullListGroupItem")
     *   )
     * )
     * @param  GuideName  $guide
     * @param  FullGuideSortField  $sort_field
     * @param  Collection|Appearance[]  $appearances
     * @return array
     */
    public static function createGroupsForFullList(
        GuideName $guide,
        FullGuideSortField $sort_field,
        $appearances
    ): array {
        switch ($sort_field) {
            case FullGuideSortField::DateAdded:
                // No grouping when sorting by date
                return [];
            case FullGuideSortField::Relevance:
                $group_tag_ids = self::getGroupTagIds($guide);
                $group_items = [];
                $ids_in_order = new Collection(array_keys($group_tag_ids));
                foreach ($appearances as $appearance) {
                    $tags = $appearance->tags->keyBy(fn (Tag $tag) => $tag->id);
                    $fit_somewhere = $ids_in_order->some(function (int $id) use ($tags, $appearance, &$group_items) {
                        $condition = isset($tags[$id]);
                        if ($condition) {
                            $group_items[$id][] = $appearance->id;
                        }
                        return $condition;
                    });
                    if (!$fit_somewhere) {
                        $group_items[0][] = $appearance->id;
                    }
                }
                return $ids_in_order
                    ->filter(fn (int $id) => isset($group_items[$id]))
                    ->map(fn (int $id) => [
                        'name' => $group_tag_ids[$id],
                        'appearance_ids' => $group_items[$id],
                    ])
                    ->values()->toArray();
            case FullGuideSortField::Alphabetically:
                $group_items = [];
                foreach ($appearances as $appearance) {
                    $first_letter = strtoupper($appearance->label[0]);
                    $key = preg_match('/^[A-Z]$/', $first_letter) ? $first_letter : '#';
                    $group_items[$key][] = $appearance->id;
                }
                return (new Collection(array_keys($group_items)))
                    ->map(fn (string $letter) => [
                        'name' => $letter,
                        'appearance_ids' => $group_items[$letter],
                    ])
                    ->toArray();
        }

        throw new \RuntimeException("Unhandled sort field $sort_field->value");
    }

    /**
     * @OA\Schema(
     *   schema="AppearancePreviewData",
     *   type="array",
     *   minItems=1,
     *   maxItems=4,
     *   format="Array of HEX color values, minimum 1, maximum 4, or null for no preview",
     *   example={"#FF0000","#00FF00","#0000FF"},
     *   @OA\Items(type="string")
     * )
     * @param  Appearance  $a
     * @return array
     */
    public static function mapPreviewAppearance(Appearance $a): array
    {
        return [
            'id' => $a->id,
            'label' => $a->label,
            'guide' => $a->guide,
            'owner_id' => $a->owner_id,
            'previewData' => $a->preview_data,
            'nutshellNames' => NutshellNames::for($a),
        ];
    }

    /**
     * @OA\Schema(
     *   schema="AutocompleteAppearance",
     *   type="object",
     *   description="The barest of properties for an appearance intended for use in autocompletion results",
     *   allOf={
     *     @OA\Schema(ref="#/components/schemas/PreviewAppearance"),
     *     @OA\Schema(
     *       required={
     *         "sprite",
     *       },
     *       additionalProperties=false,
     *       @OA\Property(
     *         property="sprite",
     *         nullable=true,
     *         description="The sprite that belongs to this appearance, or null if there is none",
     *         allOf={
     *           @OA\Schema(ref="#/components/schemas/Sprite")
     *         }
     *       )
     *     )
     *   }
     * )
     * @param  Appearance  $a
     * @param  bool  $double_size_sprite
     * @return array
     */
    public static function mapAutocompleteAppearance(Appearance $a, bool $double_size_sprite = false): array
    {
        return array_merge(self::mapPreviewAppearance($a), [
            'sprite' => self::mapSprite($a, $double_size_sprite),
        ]);
    }

    /**
     * @param  Appearance  $a
     * @param  bool  $double_size
     * @param  Media|null  $sprite_file
     * @return array|null
     */
    public static function mapSprite(Appearance $a, bool $double_size = false, ?Media $sprite_file = null): ?array
    {
        if ($sprite_file === null) {
            $sprite_file = $a->spriteFile();
        }
        if (!$sprite_file) {
            return null;
        }

        $path = $a->is_private
            ? route('appearance_sprite', ['appearance' => $a])
            : $sprite_file->getFullUrl($double_size ? Appearance::DOUBLE_SIZE_CONVERSION : '');
        return [
            'path' => $path,
            'aspect_ratio' => $sprite_file->getCustomProperty('aspect_ratio', [1, 1]),
        ];
    }

    /**
     * @param  MajorChange  $mc
     * @param  bool  $is_staff
     * @return array
     */
    public static function mapMajorChange(MajorChange $mc, bool $is_staff): array
    {
        return [
            'id' => $mc->id,
            'reason' => $mc->reason,
            'appearance' => ColorGuideHelper::mapPreviewAppearance($mc->appearance),
            'user' => $is_staff ? $mc->user->toArray() : null,
            'created_at' => $mc->created_at->toISOString(),
        ];
    }

    /**
     * @OA\Schema(
     *   schema="CommonAppearance",
     *   type="object",
     *   description="Common properties of the two main Appearance schemas",
     *   additionalProperties=false,
     *   allOf={
     *     @OA\Schema(ref="#/components/schemas/AutocompleteAppearance"),
     *     @OA\Schema(
     *       type="object",
     *       required={
     *         "order",
     *         "hasCutieMarks"
     *       },
     *       additionalProperties=false,
     *       @OA\Property(
     *         property="order",
     *         ref="#/components/schemas/Order"
     *       ),
     *       @OA\Property(
     *         property="hasCutieMarks",
     *         type="boolean",
     *         description="Indicates whether there are any cutie marks tied to this appearance"
     *       )
     *     )
     *   }
     * )
     * @OA\Schema(
     *   schema="SlimAppearanceOnly",
     *   type="object",
     *   description="Represents properties that belong to the slim appearance object only",
     *   required={
     *     "characterTagNames",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="characterTagNames",
     *     type="array",
     *     minItems=0,
     *     @OA\Items(
     *       type="string"
     *     )
     *   )
     * )
     * @OA\Schema(
     *   schema="AppearanceOnly",
     *   type="object",
     *   description="Represents properties that belong to the full appearance object only",
     *   required={
     *     "created_at",
     *     "tags",
     *     "notes",
     *     "colorGroups",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="created_at",
     *     ref="#/components/schemas/IsoStandardDate",
     *   ),
     *   @OA\Property(
     *     property="notes",
     *     type="string",
     *     format="html",
     *     nullable=true,
     *     example="Far legs use darker colors. Based on <strong>S2E21</strong>."
     *   ),
     *   @OA\Property(
     *     property="tags",
     *     type="array",
     *     minItems=0,
     *     @OA\Items(ref="#/components/schemas/SlimGuideTag")
     *   )
     * )
     * @param  Appearance  $a
     * @param  bool  $compact
     *
     * @return array
     */
    public static function mapAppearance(Appearance $a, bool $compact = false, bool $double_size_sprite = false): array
    {
        static $is_staff = null;
        if ($is_staff === null) {
            $is_staff = Permission::sufficient(Role::Staff);
        }

        $appearance = array_merge(ColorGuideHelper::mapAutocompleteAppearance($a, $double_size_sprite), [
            'has_cutie_marks' => $a->has_cutie_marks,
            'created_at' => $a->created_at->toISOString(),
        ]);

        if (!$compact) {
            $show_synonyms = false;
            if ($is_staff) {
                $hide_synonym_tags = UserPrefHelper::get(Auth::user(), UserPrefKey::ColorGuide_HideSynonymTags);
                $show_synonyms = !$hide_synonym_tags;
            }

            $tag_mapper = fn (Tag $t) => self::mapTag($t);
            $appearance['tags'] = TagHelper::getFor($a->id, $show_synonyms, true)->map($tag_mapper);
            $appearance['notes'] = $a->notes_rend;
            $last_change = MajorChange::where('appearance_id', $a->id)->max('created_at');
            $appearance['last_major_change'] = $last_change === null ? null : Carbon::parse($last_change)->toISOString();
            $appearance['color_groups'] = self::getColorGroups($a);
        } else {
            // The full list shows tags and notes too, but not the color groups. Only regular tags, synonyms stay hidden here
            $appearance['tags'] = $a->tags->whereNull('synonym_of')->sortBy([['type', 'asc'], ['name', 'asc']])->values()->map(fn (Tag $t) => self::mapTag($t));
            $appearance['notes'] = $a->notes_rend;
        }

        return $appearance;
    }

    /**
     * @param  Appearance  $a
     *
     * @return array
     */
    public static function mapDetailedAppearance(Appearance $a): array
    {
        $user = Auth::user();
        $appearance = array_merge(self::mapAppearance($a, false, true), [
            'can_edit' => $a->canBeManagedBy($user),
            'related_appearances' => $a->relatedAppearances()->get()
                ->filter(fn (Appearance $related) => $related->owner_id === null || $related->canBeManagedBy($user))
                ->map(fn (Appearance $related) => self::mapPreviewAppearance($related))
                ->values()
                ->toArray(),
            'related_shows' => $a->shows()->orderBy('show.id')->get()
                ->map(fn (Show $show) => ShowController::mapShowListItem($show))
                ->toArray(),
            // Cutie marks whose file is missing cannot be displayed, so they are left out
            'cutie_marks' => $a->cutiemarks()->get()
                ->filter(fn (CutieMark $cutiemark) => $cutiemark->vectorFile() !== null)
                ->map(fn (CutieMark $cutiemark) => self::mapCutiemark($cutiemark))
                ->values()
                ->toArray(),
        ]);

        return $appearance;
    }

    /**
     * @param  Color  $c
     *
     * @return array
     */
    public static function mapColor(Color $c)
    {
        return [
            'id' => $c->id,
            'label' => $c->label,
            'order' => $c->order,
            'hex' => $c->hex,
        ];
    }

    /**
     * @OA\Schema(
     *   schema="FavMe",
     *   type="string",
     *   description="DeviantArt's shorthand URL format, which typically takes the form of `http://fav.me/d######`, where `#` is the base-36 encoded version of the deviation's numerical ID, found at the end of the deviation URL. This value includes the leading `d`.",
     *   minLength=7,
     *   maxLength=7
     * )
     * @OA\Schema(
     *   schema="CutieMarkRotation",
     *   type="number",
     *   description="The number of degrees to rotate the cutie mark image on the UI to better reflect its potion in the preview. Purely for cosmetic use.",
     *   minimum=-45,
     *   maximum=45,
     *   default=0
     * )
     *
     * @param  CutieMark  $cm
     *
     * @return array
     */
    public static function mapCutieMark(CutieMark $cm)
    {
        $cutie_mark = [
            'id' => $cm->id,
            'facing' => $cm->facing,
            'fav_me' => $cm->favme,
            'rotation' => $cm->rotation,
            'view_url' => $cm->vectorFile()->getFullUrl(),
        ];
        if ($cm->contributor_id) {
            /** @var $contributor DeviantartUser */
            $contributor = $cm->contributor()->first();
            if ($contributor !== null) {
                $contributing_user = $contributor->user()->first();
                if ($contributing_user !== null) {
                    $cutie_mark['contributor'] = $contributing_user->toArray();
                }
            }
        }
        if ($cm->label) {
            $cutie_mark['label'] = $cm->label;
        }
        return $cutie_mark;
    }

    /**
     * @param  Tag  $t
     *
     * @return array
     */
    public static function mapTag(Tag $t)
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'type' => $t->type,
        ];
    }

    /**
     * @OA\Schema(
     *   schema="ColorGroup",
     *   type="object",
     *   description="Groups a list of colors",
     *   required={
     *     "id",
     *     "label",
     *     "order",
     *     "colors"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="id",
     *     ref="#/components/schemas/OneBasedId"
     *   ),
     *   @OA\Property(
     *     property="label",
     *     type="string",
     *     description="The name of the color group",
     *     example="Coat"
     *   ),
     *   @OA\Property(
     *     property="order",
     *     ref="#/components/schemas/Order"
     *   ),
     *   @OA\Property(
     *     property="colors",
     *     type="array",
     *     minItems=1,
     *     @OA\Items(ref="#/components/schemas/Color"),
     *     description="The list of colors inside this group"
     *   )
     * )
     * @param  ColorGroup  $cg
     *
     * @return array
     */
    public static function mapColorGroup(ColorGroup $cg)
    {
        $colors = $cg->colors ?: $cg->colors()->get();

        return [
            'id' => $cg->id,
            'label' => $cg->label,
            'order' => $cg->order,
            'colors' => $colors->map(fn (Color $c) => self::mapColor($c)),
        ];
    }

    public static function getColorGroups(Appearance $a): array
    {
        $color_groups = $a->colorGroups ?: $a->colorGroups()->with('colors')->get();
        return $color_groups->map(fn (ColorGroup $cg) => ColorGuideHelper::mapColorGroup($cg))->toArray();
    }
}
