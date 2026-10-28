<?php

namespace App\Utils;

use OpenSearch\Client;
use OpenSearch\ClientBuilder;
use OpenSearch\Common\Exceptions\BadRequest400Exception;
use OpenSearch\Common\Exceptions\Conflict409Exception;
use OpenSearch\Common\Exceptions\Forbidden403Exception;
use OpenSearch\Common\Exceptions\Missing404Exception;
use OpenSearch\Common\Exceptions\RequestTimeout408Exception;
use OpenSearch\Common\Exceptions\ScriptLangNotSupportedException;
use OpenSearch\Common\Exceptions\ServerErrorResponseException;
use OpenSearch\Common\Exceptions\TransportException;
use OpenSearch\Common\Exceptions\Unauthorized401Exception;
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

    /** Replaces the client, for tests that must not talk to a server */
    public static function use(?Client $client): void
    {
        self::$client = $client;
    }

    /** Forgets the client, for tests that change the configuration */
    public static function reset(): void
    {
        self::$client = null;
    }

    /** The HTTP status of a failed request, null when there was no response */
    public static function status(Throwable $e): ?int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        // The legacy exceptions thrown by the client's connection carry no status, only their class
        return match (true) {
            $e instanceof BadRequest400Exception, $e instanceof ScriptLangNotSupportedException => 400,
            $e instanceof Unauthorized401Exception => 401,
            $e instanceof Forbidden403Exception => 403,
            $e instanceof Missing404Exception => 404,
            $e instanceof RequestTimeout408Exception => 408,
            $e instanceof Conflict409Exception => 409,
            $e instanceof ServerErrorResponseException => 500,
            default => null,
        };
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
