<?php

namespace App\Utils;

use App\Models\Appearance;
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
