<?php

namespace App\Utils;

use App\Models\Appearance;
use App\Models\PinnedAppearance;
use App\Models\Tag;
use OpenSearch\Common\Exceptions\OpenSearchException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the `appearances` OpenSearch index (shared with Winterchilla) in sync. Only official guide appearances are indexed,
 * personal guide ones are never searched. Does nothing in the testing environment, which has no OpenSearch.
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
            Search::client()->update([
                'index' => self::INDEX,
                'id' => $appearance->id,
                'body' => ['doc' => $body, 'upsert' => $body],
            ]);
        } catch (OpenSearchException $e) {
            if (!Search::isDown($e)) {
                throw $e;
            }
            Log::error("OpenSearch server was down when attempting to index appearance {$appearance->id}");
        }
    }

    public static function remove(Appearance $appearance): void
    {
        if (App::environment('testing') || $appearance->owner_id !== null) {
            return;
        }

        try {
            Search::client()->delete(['index' => self::INDEX, 'id' => $appearance->id]);
        } catch (OpenSearchException $e) {
            if (Search::isDown($e)) {
                Log::error("OpenSearch server was down when attempting to remove appearance {$appearance->id}");
            } elseif (Search::status($e) !== 404) {
                // (A 404 means it was not indexed, nothing to remove)
                throw $e;
            }
        }
    }

    /**
     * Drops and rebuilds the whole index (same mapping and analyzer as Winterchilla's) from every official, unpinned appearance
     *
     * @return int Number of indexed appearances
     */
    public static function rebuild(): int
    {
        $client = Search::client();
        try {
            $client->indices()->delete(['index' => self::INDEX]);
        } catch (OpenSearchException $e) {
            // The index does not exist yet
            if (Search::status($e) !== 404) {
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
            $response = $client->bulk(['body' => $body]);
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
