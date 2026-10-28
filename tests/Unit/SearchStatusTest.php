<?php

namespace Tests\Unit;

use App\Utils\Search;
use Mockery;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\BadRequest400Exception;
use OpenSearch\Common\Exceptions\Missing404Exception;
use PHPUnit\Framework\TestCase;

class SearchStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        Search::use(null);
        Mockery::close();
        parent::tearDown();
    }

    public function testLegacyExceptionsCarryTheirStatus(): void
    {
        // The classes announce their removal when they load, which is not what is under test
        set_error_handler(fn () => true, E_USER_DEPRECATED);
        $bad = new BadRequest400Exception('{}');
        $missing = new Missing404Exception('{}');
        restore_error_handler();

        $this->assertSame(400, Search::status($bad));
        $this->assertSame(404, Search::status($missing));
        $this->assertNull(Search::status(new \RuntimeException()));
    }
}
