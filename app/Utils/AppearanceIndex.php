<?php

namespace App\Utils;

use App\Models\Appearance;
use App\Models\PinnedAppearance;
use App\Models\Tag;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Elasticsearch;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the `appearances` ElasticSearch index (shared with Winterchilla) in sync. Only official guide appearances are indexed,
 * personal guide ones are never searched. Does nothing in the testing environment, which has no ElasticSearch.
 */
class AppearanceIndex
{
    private const INDEX = 'appearances';

    public static function update(Appearance $appearance): void
    {
        if (App::environment('testing') || $appearance->owner_id !== null) {
            return;
        }

        $body = self::body($appearance);
        try {
            Elasticsearch::connection()->update([
                'index' => self::INDEX,
                'id' => $appearance->id,
                'body' => ['doc' => $body, 'upsert' => $body],
            ]);
        } catch (NoNodeAvailableException|ServerResponseException $e) {
            Log::error("ElasticSearch server was down when attempting to index appearance {$appearance->id}");
        }
    }

    public static function remove(Appearance $appearance): void
    {
        if (App::environment('testing') || $appearance->owner_id !== null) {
            return;
        }

        try {
            Elasticsearch::connection()->delete(['index' => self::INDEX, 'id' => $appearance->id]);
        } catch (ClientResponseException $e) {
            // Not indexed, nothing to remove
            if ($e->getCode() !== 404) {
                throw $e;
            }
        } catch (NoNodeAvailableException|ServerResponseException $e) {
            Log::error("ElasticSearch server was down when attempting to remove appearance {$appearance->id}");
        }
    }

    /**
     * Drops and rebuilds the whole index (same mapping and analyzer as Winterchilla's) from every official, unpinned appearance
     *
     * @return int Number of indexed appearances
     */
    public static function rebuild(): int
    {
        $client = Elasticsearch::connection();
        try {
            $client->indices()->delete(['index' => self::INDEX]);
        } catch (ClientResponseException $e) {
            // The index does not exist yet
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        $client->indices()->create([
            'index' => self::INDEX,
            'body' => [
                'mappings' => ['properties' => [
                    'label' => ['type' => 'text', 'analyzer' => 'overkill'],
                    'order' => ['type' => 'integer'],
                    'guide' => ['type' => 'keyword'],
                    'private' => ['type' => 'boolean'],
                    'tags' => ['type' => 'text', 'analyzer' => 'overkill'],
                ]],
                'settings' => [
                    // Single-node setup, replicas could never be allocated
                    'number_of_replicas' => 0,
                    'analysis' => [
                        'analyzer' => ['overkill' => ['type' => 'custom', 'tokenizer' => 'overkill', 'filter' => ['lowercase']]],
                        'tokenizer' => ['overkill' => ['type' => 'edge_ngram', 'min_gram' => 2, 'max_gram' => 30, 'token_chars' => ['letter', 'digit']]],
                    ],
                ],
            ],
        ]);

        $count = 0;
        $pinned = PinnedAppearance::pluck('appearance_id');
        Appearance::whereNull('owner_id')->whereNotIn('id', $pinned)->chunkById(100, function ($appearances) use ($client, &$count) {
            $body = [];
            foreach ($appearances as $appearance) {
                $body[] = ['index' => ['_index' => self::INDEX, '_id' => $appearance->id]];
                $body[] = self::body($appearance);
            }
            $response = $client->bulk(['body' => $body])->asArray();
            if (!empty($response['errors'])) {
                throw new \RuntimeException('Bulk indexing reported errors: '.json_encode($response['items']));
            }
            $count += count($appearances);
        });

        return $count;
    }

    public static function body(Appearance $appearance): array
    {
        $tags = $appearance->tags()->whereNull('synonym_of')->get();
        $synonyms = Tag::whereIn('synonym_of', $tags->pluck('id'))->get();

        return [
            'label' => $appearance->label,
            'order' => $appearance->order,
            'private' => (bool) $appearance->private,
            'guide' => $appearance->guide?->value,
            'tags' => $tags->pluck('name')->merge($synonyms->pluck('name'))->values()->all(),
        ];
    }
}
