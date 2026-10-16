<?php

namespace Tests\Unit;

use App\Utils\SpriteColorCheck;
use PHPUnit\Framework\TestCase;

class SpriteColorCheckTest extends TestCase
{
    private const PALETTE = [
        ['group' => 'Coat', 'label' => 'Fill', 'hex' => '#FF0000'],
        ['group' => 'Mane', 'label' => 'Fill', 'hex' => '#0000FF'],
        ['group' => 'Mane', 'label' => 'Fill (Younger)', 'hex' => '#00FF00'],
    ];

    private function kinds(array $counts): array
    {
        return array_column(SpriteColorCheck::check($counts, self::PALETTE)['colors'], 'kind', 'hex');
    }

    public function testBlendsOfListedColorsAreExplained(): void
    {
        $kinds = $this->kinds([
            '#FF0000' => [0 => 50], '#0000FF' => [0 => 50],
            '#800080' => [0 => 9], // half way between the two
            '#000000' => [0 => 9], // multiply of the two
            '#FF00FF' => [0 => 9], // screen of the two
            '#12AB34' => [0 => 9],
        ]);

        $this->assertSame(['listed', 'listed', 'blend', 'blend', 'blend', 'unknown'], array_values($kinds));
    }

    public function testBlendsNeedBothParentsInTheSprite(): void
    {
        $this->assertSame('unknown', $this->kinds(['#FF0000' => [0 => 50], '#800080' => [0 => 9]])['#800080']);
    }

    public function testSemiTransparentColorsAreNotJudgedAndSpecksAreCountedApart(): void
    {
        $result = SpriteColorCheck::check(['#FF0000' => [0 => 50], '#123456' => [64 => 30], '#FE0000' => [0 => 2], '#ABCDEF' => [0 => 3]], self::PALETTE);

        $this->assertSame(['listed', 'translucent', 'unknownSpeck', 'nearSpeck'], array_column($result['colors'], 'kind'));
        $this->assertSame(['missing' => 1, 'translucentOnly' => 0, 'near' => 0, 'unknown' => 0, 'specks' => 2, 'ok' => false], $result['summary']);
    }

    public function testOptionalColorsAreNotRequired(): void
    {
        $result = SpriteColorCheck::check(['#FF0000' => [0 => 50], '#0000FF' => [0 => 50]], self::PALETTE);

        $this->assertSame(['present', 'present', 'missing'], array_column($result['palette'], 'status'));
        $this->assertTrue($result['palette'][2]['optional']);
        $this->assertTrue($result['summary']['ok']);
    }
}
