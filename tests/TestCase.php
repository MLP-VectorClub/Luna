<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
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
    }
}
