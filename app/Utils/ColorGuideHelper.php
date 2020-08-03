<?php

namespace App\Utils;

use App\Appearances;
use App\CoreUtils;
use App\DB;
use App\Enums\GuideName;
use App\Enums\MlpGeneration;
use App\Models\Appearance;
use App\Pagination;
use App\ShowHelper;
use Elasticsearch;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use Elasticsearch\Common\Exceptions\NoNodesAvailableException;
use Elasticsearch\Common\Exceptions\ServerErrorResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use ONGR\ElasticsearchDSL;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use function is_array;

class ColorGuideHelper
{
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
            /** @var Elasticsearch\Client $client */
            $client = Elasticsearch::connection();
            $elastic_avail = $client->ping();
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
            $search_query = preg_replace("~[^\w\s*?'-]~", '', $search_for);
            $multi_match = new ElasticsearchDSL\Query\FullText\MultiMatchQuery(
                ['label', 'tags'],
                $search_query,
                [
                    'type' => 'cross_fields',
                    'minimum_should_match' => '100%',
                ]
            );
            $search_query->addQuery($multi_match);
        }

        $sort = new ElasticsearchDSL\Sort\FieldSort('order', 'asc');
        $search_query->addSort($sort);

        $bool_query = new BoolQuery();
        $bool_query->add(new TermQuery('guide', $guide), BoolQuery::MUST);
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
            $ids = [];
            foreach ($search_results['hits']['hits'] as $i => $hit) {
                $ids[$hit['_id']] = $i;
            }

            /** @var Appearance[] $appearances */
            $appearances = Appearance::ordered()
                ->whereIn('id', array_keys($ids))
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

        /** @var Elasticsearch\Client $client */
        $client = Elasticsearch::connection();
        return $client->search($params);
    }
}
