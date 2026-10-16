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

    public function testColorsListedWithAnOpacityExplainTheirCompositeOverASpriteColor(): void
    {
        $palette = [
            ['group' => 'Coat', 'label' => 'Fill', 'hex' => '#FFFFFF'],
            ['group' => 'Glasses', 'label' => 'Lens', 'hex' => '#0000FF', 'opacity' => 50, 'blendMode' => null],
            ['group' => 'Glasses', 'label' => 'Shine', 'hex' => '#808080', 'opacity' => 50, 'blendMode' => 'multiply'],
        ];
        // 50% blue over white, and 50% grey multiplied over white
        $result = SpriteColorCheck::check(['#FFFFFF' => [0 => 50], '#8080FF' => [0 => 9], '#BFBFBF' => [0 => 9], '#0000FF' => [64 => 20]], $palette, []);
        $byHex = array_column($result['colors'], null, 'hex');

        $this->assertSame('blend', $byHex['#8080FF']['kind']);
        $this->assertSame(['#0000FF', '#FFFFFF'], $byHex['#8080FF']['blendOf']);
        $this->assertSame(['normal', 50], [$byHex['#8080FF']['blendMode'], $byHex['#8080FF']['blendOpacity']]);
        $this->assertSame(['multiply', ['#808080', '#FFFFFF']], [$byHex['#BFBFBF']['blendMode'], $byHex['#BFBFBF']['blendOf']]);
        // Listed as semi-transparent and only drawn that way: as expected
        $this->assertSame(['present', 'normal'], [$result['palette'][1]['status'], $result['palette'][1]['blendMode']]);
        $this->assertSame('missing', $result['palette'][2]['status']);
    }
}
