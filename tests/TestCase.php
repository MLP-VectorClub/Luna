<?php

namespace Tests;

use App\Utils\Search;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery;
use OpenSearch\Client;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        Search::use(null);

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase drops every table, so never let it near a real database
        $database = config('database.connections.'.config('database.default').'.database');
        if (!str_ends_with((string) $database, '_test')) {
            throw new RuntimeException("Refusing to run tests against database '$database', its name must end in _test");
        }

        // The cached responses would leak between tests
        config(['responsecache.enabled' => false]);

        // No test talks to an OpenSearch server: a search finds nothing, as it does when the index is missing
        $search = Mockery::mock(Client::class);
        $search->shouldReceive('search')->andReturn([]);
        Search::use($search);
    }
}
