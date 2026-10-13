<?php

return [

    /**
     * The OpenSearch server behind the color guide search. Every key is optional, the defaults are a local server
     * without the security plugin.
     */

    // Host strings of the cluster nodes, e.g. "http://localhost:9200"
    'hosts' => array_filter(explode(',', (string) env('OPENSEARCH_HOSTS', 'http://localhost:9200'))),

    // Basic authentication, when the security plugin is on
    'username' => env('OPENSEARCH_USERNAME'),
    'password' => env('OPENSEARCH_PASSWORD'),

    // Path to a CA bundle for a self-signed certificate, or false to skip verification. Null keeps the default
    'ssl_verification' => env('OPENSEARCH_CA_BUNDLE'),

    // Seconds, a search that is slow or unreachable must not hold up the page
    'timeout' => (float) env('OPENSEARCH_TIMEOUT', 15),
    'connect_timeout' => (float) env('OPENSEARCH_CONNECT_TIMEOUT', 5),

    // Retries per request, null for the client's default (one per node)
    'retries' => env('OPENSEARCH_RETRIES'),

];
