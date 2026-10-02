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

    public function testSpriteTracingMatchesWinterchilla(): void
    {
        $dir = __DIR__.'/../fixtures/winterchilla';
        $map = AppearanceImages::traceSprite("$dir/sprite.png");

        $this->assertSame(json_decode(file_get_contents("$dir/sprite-map.json"), true), json_decode(json_encode($map), true));
        $this->assertSame(file_get_contents("$dir/sprite.svg"), AppearanceImages::spriteSvg($map));
    }

    public static function swatchAppearances(): array
    {
        return array_map(fn(string $file) => [basename($file, '.json')], glob(__DIR__.'/../fixtures/winterchilla/swatches/*.json'));
    }

    #[DataProvider('swatchAppearances')]
    public function testSwatchFilesMatchWinterchilla(string $id): void
    {
        $dir = __DIR__.'/../fixtures/winterchilla/swatches';

        $golden = file_get_contents("$dir/$id.json");
        $decoded = json_decode($golden, true);
        $label = array_key_last($decoded);
        $json = AppearanceImages::swatchJson($label, $decoded[$label], 0);
        $this->assertSame($golden, str_replace('1970-01-01 00:00:00 GMT', '{TIME}', $json));

        $golden = file_get_contents("$dir/$id.gpl");
        $colors = [];
        foreach ($decoded[$label] as $group => $group_colors) {
            foreach ($group_colors as $color_label => $hex) {
                [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');
                $colors[] = [$r, $g, $b, "$group | $color_label"];
            }
        }
        $this->assertSame($golden, str_replace('1970-01-01 00:00:00 GMT', '{TIME}', AppearanceImages::gimpPalette($label, $colors, 0)));
    }
}
