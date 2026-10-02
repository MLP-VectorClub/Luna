<?php

namespace Tests\Feature;

use App\Utils\AppearanceImages;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The expected files under tests/fixtures/winterchilla are produced by Winterchilla's own code
 * (scripts/generate-winterchilla-preview-goldens.php), so these tests pin Luna's output to what Winterchilla generates.
 */
class AppearanceImagesTest extends TestCase
{
    public static function previewCases(): array
    {
        return array_map(fn(string $name) => [$name], ['none', 'one', 'two', 'three', 'four']);
    }

    #[DataProvider('previewCases')]
    public function testPreviewSvgMatchesWinterchilla(string $name): void
    {
        $dir = __DIR__.'/../fixtures/winterchilla';
        $hexes = json_decode(file_get_contents("$dir/preview-$name.hexes.json"), true);

        $this->assertSame(file_get_contents("$dir/preview-$name.svg"), AppearanceImages::previewSvg($hexes));
    }

    public static function facingCases(): array
    {
        $cases = [];
        foreach (['full', 'derived', 'labels', 'empty'] as $name) {
            foreach (['left', 'right'] as $facing) {
                $cases["$name $facing"] = [$name, $facing];
            }
        }

        return $cases;
    }

    #[DataProvider('facingCases')]
    public function testFacingSvgMatchesWinterchilla(string $name, string $facing): void
    {
        $dir = __DIR__.'/../fixtures/winterchilla';
        $rows = json_decode(file_get_contents("$dir/facing-$name.json"), true);

        $this->assertSame(file_get_contents("$dir/facing-$name-$facing.svg"), AppearanceImages::facingSvg($facing, $rows));
    }
}
