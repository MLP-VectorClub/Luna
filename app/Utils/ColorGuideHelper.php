<?php

namespace App\Utils;

use App\Appearances;
use App\CoreUtils;
use App\DB;
use App\Enums\FullGuideSortField;
use App\Enums\GuideName;
use App\Enums\MlpGeneration;
use App\Models\Appearance;
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
use Illuminate\Support\Str;
use ONGR\ElasticsearchDSL;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use OpenApi\Annotations as OA;
use function is_array;

class ColorGuideHelper
{
    public const GROUP_TAG_IDS_ASSOC = [
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
        GuideName::PonyLife => [
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

    public static function mapGuideToMlpGeneration(GuideName $guide_name): ?MlpGeneration
    {
        static $guide_map;

        if (!$guide_map) {
            $guide_map = [
                GuideName::FriendshipIsMagic()->value => MlpGeneration::FriendshipIsMagic(),
                GuideName::PonyLife()->value => MlpGeneration::PonyLife(),
            ];
        }

        return $guide_map[$guide_name->value] ?? null;
    }

    public static function isElasticAvailable(): bool
    {
        try {
            $elastic_avail = Elasticsearch::connection()->ping();
        } catch (NoNodesAvailableException|ServerErrorResponseException $e) {
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
        switch ($sort_field->value) {
            case FullGuideSortField::DateAdded:
                // No grouping when sorting by date
                return [];
            case FullGuideSortField::Relevance:
                $group_items = [];
                $ids_in_order = new Collection(array_keys(self::GROUP_TAG_IDS_ASSOC[$guide->value]));
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
                        'name' => self::GROUP_TAG_IDS_ASSOC[$guide->value][$id],
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
     *   description="Minimal set of properties to display an appearance link, optinally with a colored preview",
     *   required={
     *     "id",
     *     "label",
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
     * @return array
     */
    public static function mapAutocompleteAppearance(Appearance $a): array
    {
        return array_merge(self::mapPreviewAppearance($a), [
            'sprite' => self::mapSprite($a),
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
     *
     * @return array|null
     */
    public static function mapSprite(Appearance $a): ?array
    {
        $sprite_file = $a->spriteFile();
        if (!$sprite_file) {
            return null;
        }

        $sprite_file = $a->spriteFile();

        return [
            'path' => $sprite_file->getFullUrl(),
            'aspect_ratio' => $sprite_file->getCustomProperty('aspect_ratio', [1, 1]),
        ];
    }
}
