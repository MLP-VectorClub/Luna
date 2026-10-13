<?php

namespace App\Utils;

use OpenSearch\Client;
use OpenSearch\ClientBuilder;
use OpenSearch\Common\Exceptions\TransportException;
use OpenSearch\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The OpenSearch client and what Luna needs to know about its failures. The client reports a failed request through exceptions that carry
 * the HTTP status (`HttpExceptionInterface`) with the JSON error body as their message, and an unreachable server through `TransportException`.
 */
class Search
{
    private static ?Client $client = null;

    public static function client(): Client
    {
        if (self::$client !== null) {
            return self::$client;
        }

        $builder = ClientBuilder::create()
            ->setHosts(config('opensearch.hosts'))
            ->setConnectionParams(['client' => [
                'timeout' => config('opensearch.timeout'),
                'connect_timeout' => config('opensearch.connect_timeout'),
            ]]);
        if (config('opensearch.username')) {
            $builder->setBasicAuthentication(config('opensearch.username'), (string) config('opensearch.password'));
        }
        if (config('opensearch.ssl_verification') !== null) {
            $builder->setSSLVerification(config('opensearch.ssl_verification'));
        }
        if (config('opensearch.retries') !== null) {
            $builder->setRetries((int) config('opensearch.retries'));
        }

        return self::$client = $builder->build();
    }

    /** Forgets the client, for tests that change the configuration */
    public static function reset(): void
    {
        self::$client = null;
    }

    /** The HTTP status of a failed request, null when there was no response */
    public static function status(Throwable $e): ?int
    {
        return $e instanceof HttpExceptionInterface ? $e->getStatusCode() : null;
    }

    /** The decoded JSON error body of a failed request */
    public static function errorBody(Throwable $e): array
    {
        $decoded = json_decode($e->getMessage(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Whether the server could not be reached, or failed itself */
    public static function isDown(Throwable $e): bool
    {
        return $e instanceof TransportException || (self::status($e) ?? 0) >= 500;
    }
}
