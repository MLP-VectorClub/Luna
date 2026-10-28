<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Utils\ColorGuideHelper;
use App\Utils\Search;
use Mockery;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\BadRequest400Exception;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Tests\TestCase;

#[IgnoreDeprecations]
class GuideSearchPagingTest extends TestCase
{
    public function testPagesBeyondTheResultWindowAreEmptyWithoutAskingTheServer(): void
    {
        $search = Mockery::mock(Client::class);
        $search->shouldNotReceive('search');
        Search::use($search);

        $result = ColorGuideHelper::searchGuide(5985824810, 7, GuideName::FriendshipIsMagic);

        $this->assertCount(0, $result->items());
    }

    public function testAPagingErrorFromTheServerIsNoResults(): void
    {
        $search = Mockery::mock(Client::class);
        $search->shouldReceive('search')->andThrow(new BadRequest400Exception(
            '{"error":{"reason":"Failed to parse int parameter [from] with value [1]"},"status":400}'
        ));
        Search::use($search);

        $result = ColorGuideHelper::searchGuide(2, 7, GuideName::FriendshipIsMagic);

        $this->assertCount(0, $result->items());
    }
}
