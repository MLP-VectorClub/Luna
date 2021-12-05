<?php

namespace App\Utils;

use App\Appearances;
use App\CoreUtils;
use App\DB;
use App\Enums\FullGuideSortField;
use App\Enums\GuideName;
use App\Enums\MlpGeneration;
use App\Enums\Role;
use App\Enums\TagType;
use App\Enums\UserPrefKey;
use App\Models\Appearance;
use App\Models\Color;
use App\Models\ColorGroup;
use App\Models\CutieMark;
use App\Models\DeviantartUser;
use App\Models\MajorChange;
use App\Models\Tag;
use App\Pagination;
use App\ShowHelper;
use Elasticsearch;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use Elasticsearch\Common\Exceptions\NoNodesAvailableException;
use Elasticsearch\Common\Exceptions\ServerErrorResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use ONGR\ElasticsearchDSL;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use OpenApi\Annotations as OA;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use function is_array;

$GROUP_TAG_IDS_ASSOC = [
    GuideName::FriendshipIsMagic->value => [
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
    GuideName::EquestriaGirls->value => [
        76 => 'Humans',
        0 => 'Other',
    ],
    GuideName::PonyLife->value => [
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
];

class ColorGuideHelper
{
    public static function mapGuideToMlpGeneration(GuideName $guide_name): ?MlpGeneration
    {
        static $guide_map;

        if (!$guide_map) {
            $guide_map = [
                GuideName::FriendshipIsMagic->value => MlpGeneration::FriendshipIsMagic,
                GuideName::PonyLife->value => MlpGeneration::PonyLife,
            ];
        }

        return $guide_map[$guide_name->value] ?? null;
    }

    public static function isElasticAvailable(): bool
    {
        try {
            $elastic_avail = Elasticsearch::connection()->ping();
        } catch (NoNodesAvailableException | ServerErrorResponseException $e) {
            return false;
        }

        return $elastic_avail;
    }


    /**
     * @param  int  $page
     * @param  int  $per_page
     * @param  GuideName  $guide
     * @param  string|null  $search_for
     * @return LengthAwarePaginator
     * @throws BadRequest400Exception
     * @throws ServerErrorResponseException
     */
    public static function searchGuide(
        int $page,
        int $per_page,
        GuideName $guide,
        ?string $search_for = null
    ): LengthAwarePaginator {
        $paginator = new LengthAwarePaginator([], 0, $per_page, $page);
        $search_query = new ElasticsearchDSL\Search();

        // Search query exists
        if ($search_for !== null) {
            $search_for_sanitized = preg_replace("~[^\w\s*?'-]~", '', $search_for);
            if ($search_for_sanitized !== '') {
                $multi_match = new ElasticsearchDSL\Query\FullText\MultiMatchQuery(
                    ['label', 'tags'],
                    $search_for_sanitized,
                    [
                        'type' => 'cross_fields',
                        'minimum_should_match' => '100%',
                    ]
                );
                $search_query->addQuery($multi_match);
            }
        }

        $sort = new ElasticsearchDSL\Sort\FieldSort('order', 'asc');
        $search_query->addSort($sort);

        $bool_query = new BoolQuery();
        $bool_query->add(new TermQuery('guide', $guide->value), BoolQuery::MUST);
        $search_query->addQuery($bool_query);

        $search_query->setSource(false);

        try {
            $search_results = self::searchElastic($search_query->toArray(), $paginator);
        } catch (Missing404Exception $e) {
            $search_results = [];
        } catch (ServerErrorResponseException | BadRequest400Exception $e) {
            $message = $e->getMessage();
            if (!Str::contains($message, 'Result window is too large, from + size must be less than or equal to')
                && !Str::contains($message, 'Failed to parse int parameter [from] with value')
            ) {
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

        return Elasticsearch::connection()->search($params);
    }

    /**
     * @OA\Schema(
     *   schema="GuideFullListGroupItem",
     *   type="object",
     *   required={
     *     "label",
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
        global $GROUP_TAG_IDS_ASSOC;
        switch ($sort_field->value) {
            case FullGuideSortField::DateAdded:
                // No grouping when sorting by date
                return [];
            case FullGuideSortField::Relevance:
                $group_items = [];
                $ids_in_order = new Collection(array_keys($GROUP_TAG_IDS_ASSOC[$guide->value]));
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
                        'name' => $GROUP_TAG_IDS_ASSOC[$guide->value][$id],
                        'appearance_ids' => $group_items[$id],
                    ])
                    ->toArray();
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

        throw new \RuntimeException("Unhandled sort field $sort_field");
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
     * @OA\Schema(
     *   schema="PreviewAppearance",
     *   type="object",
     *   description="Minimal set of properties to display an appearance link, optionally with a colored preview",
     *   required={
     *     "id",
     *     "label",
     *     "guide",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="id",
     *     allOf={
     *       @OA\Schema(ref="#/components/schemas/ZeroBasedId")
     *     }
     *   ),
     *   @OA\Property(
     *     property="label",
     *     type="string",
     *     description="The name of the appearance",
     *     example="Twinkle Sprinkle",
     *   ),
     *   @OA\Property(
     *     property="guide",
     *     allOf={
     *       @OA\Schema(ref="#/components/schemas/GuideName")
     *     }
     *   ),
     *   @OA\Property(
     *     property="previewData",
     *     ref="#/components/schemas/AppearancePreviewData",
     *   ),
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
            'previewData' => $a->preview_data,
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
     * @OA\Schema(
     *   schema="Sprite",
     *   type="object",
     *   description="Data related to an appearance's sprite file. The actual file is available from a different endpoint.",
     *   required={
     *     "path",
     *     "aspectRatio",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="path",
     *     type="string",
     *     format="URL",
     *     description="The full URL of the current sprite image"
     *   ),
     *   @OA\Property(
     *     property="aspectRatio",
     *     type="array",
     *     items={
     *       "type": "number",
     *     },
     *     minItems=2,
     *     maxItems=2,
     *     description="The width and height of the sprite expressed in the smallest numbers possible while retaining the same aspect ratio. Useful for calculating placeholder element sizes."
     *   ),
     * )
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
     * @OA\Schema(
     *   schema="MajorChange",
     *   type="object",
     *   description="The details for the major change entry",
     *   required={
     *     "id",
     *     "reason",
     *     "appearance",
     *     "user",
     *     "createdAt",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="id",
     *     allOf={
     *       @OA\Schema(ref="#/components/schemas/OneBasedId")
     *     }
     *   ),
     *   @OA\Property(
     *     property="reason",
     *     type="string",
     *     description="The reason for the change",
     *     example="Updated coat colors"
     *   ),
     *   @OA\Property(
     *     property="appearance",
     *     description="The appearance the change was made on",
     *     allOf={
     *       @OA\Schema(ref="#/components/schemas/PreviewAppearance")
     *     }
     *   ),
     *   @OA\Property(
     *     property="user",
     *     description="The identifier for the user who created the appearance",
     *     nullable=true,
     *     allOf={
     *       @OA\Schema(ref="#/components/schemas/BarePublicUser")
     *     }
     *   ),
     *   @OA\Property(
     *     property="createdAt",
     *     ref="#/components/schemas/IsoStandardDate"
     *   ),
     * )
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
     *   schema="SlimAppearance",
     *   type="object",
     *   description="A less heavy version of the regular Appearance schema",
     *   required={
     *     "characterTagNames",
     *   },
     *   additionalProperties=false,
     *   allOf={
     *     @OA\Schema(ref="#/components/schemas/CommonAppearance"),
     *     @OA\Schema(ref="#/components/schemas/SlimAppearanceOnly")
     *   }
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
     * @OA\Schema(
     *   schema="Appearance",
     *   type="object",
     *   description="Represents an entry in the color guide",
     *   additionalProperties=false,
     *   allOf={
     *     @OA\Schema(ref="#/components/schemas/CommonAppearance"),
     *     @OA\Schema(ref="#/components/schemas/AppearanceOnly"),
     *     @OA\Schema(ref="#/components/schemas/ListOfColorGroups")
     *   }
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
            'order' => $a->order,
            'has_cutie_marks' => $a->has_cutie_marks,
        ]);

        if (!$compact) {
            $show_synonyms = false;
            if ($is_staff) {
                $hide_synonym_tags = UserPrefHelper::get(Auth::user(), UserPrefKey::ColorGuide_HideSynonymTags);
                $show_synonyms = !$hide_synonym_tags;
            }

            $tag_mapper = fn (Tag $t) => self::mapTag($t);
            $appearance['created_at'] = $a->created_at->toISOString();
            $appearance['tags'] = TagHelper::getFor($a->id, $show_synonyms, true)->map($tag_mapper);
            $appearance['notes'] = $a->notes_rend;
            $appearance['color_groups'] = self::getColorGroups($a);
        } else {
            $appearance['character_tag_names'] = $a->tags
                ->filter(fn (Tag $tag) => $tag->type === TagType::Character)
                ->flatMap(fn (Tag $tag) => [$tag, ...$tag->synonymTo])
                ->pluck('name');
        }

        return $appearance;
    }

    /**
     * @OA\Schema(
     *   schema="DetailedAppearance",
     *   type="object",
     *   description="An appearance object containing the full range of information available",
     *   additionalProperties=false,
     *   allOf={
     *     @OA\Schema(ref="#/components/schemas/Appearance"),
     *     @OA\Schema(
     *       type="object",
     *       required={
     *         "cutieMarks",
     *       },
     *       additionalProperties=false,
     *       @OA\Property(
     *         property="cutieMarks",
     *         type="array",
     *         description="The list of cutie mark object associated with this appearance",
     *         @OA\Items(ref="#/components/schemas/CutieMark"),
     *         minItems=0,
     *       )
     *     )
     *   }
     * )
     * @param  Appearance  $a
     *
     * @return array
     */
    public static function mapDetailedAppearance(Appearance $a): array
    {
        $appearance = array_merge(self::mapAppearance($a, false, true), [
            'cutie_marks' => $a->cutiemarks()->chunkMap(
                fn (CutieMark $cutiemark) => self::mapCutiemark($cutiemark)
            )->toArray(),
        ]);

        return $appearance;
    }

    /**
     * @OA\Schema(
     *   schema="Color",
     *   type="object",
     *   description="A color entry",
     *   required={
     *     "id",
     *     "label",
     *     "order",
     *     "hex"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="id",
     *     ref="#/components/schemas/OneBasedId"
     *   ),
     *   @OA\Property(
     *     property="label",
     *     type="string",
     *     description="The name of the color",
     *     example="Fill"
     *   ),
     *   @OA\Property(
     *     property="order",
     *     ref="#/components/schemas/Order"
     *   ),
     *   @OA\Property(
     *     property="hex",
     *     type="string",
     *     format="#RRGGBB",
     *     description="The color value in uppercase hexadecimal form, including a # prefix",
     *     example="#6181B6"
     *   )
     * )
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
     * @OA\Schema(
     *   schema="CutieMark",
     *   type="object",
     *   description="A cutie mark entry",
     *   required={
     *     "id",
     *     "viewUrl",
     *     "facing",
     *     "rotation",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="id",
     *     ref="#/components/schemas/OneBasedId"
     *   ),
     *   @OA\Property(
     *     property="viewUrl",
     *     type="string",
     *     description="The URL used for displaying the cutie mark SVG file.",
     *   ),
     *   @OA\Property(
     *     property="facing",
     *     nullable=true,
     *     description="The direction the character is facing when this cutie mark should be used. `null` is used to indicate when the image is the same on both sides, meaning it's symmetrical.",
     *     allOf={
     *       @OA\Schema(ref="#/components/schemas/CutieMarkFacing")
     *     }
     *   ),
     *   @OA\Property(
     *     property="favMe",
     *     description="Optional link to a deviation on DeviantArt that is the original source of this cutie mark vector, for the sake of giving credit.",
     *     allOf={
     *       @OA\Schema(ref="#/components/schemas/FavMe")
     *     }
     *   ),
     *   @OA\Property(
     *     property="rotation",
     *     ref="#/components/schemas/CutieMarkRotation"
     *   ),
     *   @OA\Property(
     *     property="contributor",
     *     description="Optional details of the DeviantArt user who contributed this cutie mark.",
     *     allOf={
     *       @OA\Schema(ref="#/components/schemas/PublicUser")
     *     }
     *   ),
     *   @OA\Property(
     *     property="label",
     *     type="string",
     *     description="Optional label in case the cutie mark warrants additional information, e.g. only used for certain kind of characters. Should be given higher priority on the UI than the facing information.",
     *   ),
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
     * @OA\Schema(
     *   schema="SlimGuideTag",
     *   type="object",
     *   additionalProperties=false,
     *   required={
     *     "id",
     *     "name",
     *   },
     *   @OA\Property(
     *     property="id",
     *     ref="#/components/schemas/OneBasedId"
     *   ),
     *   @OA\Property(
     *     property="name",
     *     type="string",
     *     minLength=1,
     *     maxLength=255,
     *     example="mane six",
     *     description="Tag name (all lowercase)"
     *   ),
     *   @OA\Property(
     *     property="type",
     *     ref="#/components/schemas/TagType"
     *   ),
     *   @OA\Property(
     *     property="synonymOf",
     *     ref="#/components/schemas/OneBasedId"
     *   ),
     * )
     * @param  Tag  $t
     *
     * @return array
     */
    public static function mapTag(Tag $t)
    {
        $tag = [
            'id' => $t->id,
            'name' => $t->name,
        ];
        if ($t->type !== null) {
            $tag['type'] = $t->type;
        }
        if ($t->synonym_of !== null) {
            $tag['synonym_of'] = $t->synonym_of;
        }
        return $tag;
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
