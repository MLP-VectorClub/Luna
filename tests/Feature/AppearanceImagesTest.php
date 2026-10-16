<?php

namespace Tests\Feature;

use App\Utils\AppearanceImages;
use App\Utils\SpriteImage;
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

    /**
     * Draws the SVG back onto a grid with the even-odd rule: path => hex/opacity per pixel
     */
    private static function rasterize(string $svg, int $width, int $height): array
    {
        $out = array_fill(0, $width * $height, null);
        preg_match_all("/<path fill='(#[0-9A-F]{6})'(?: opacity='([0-9.]+)')? fill-rule='evenodd' d='([^']*)'/", $svg, $paths, PREG_SET_ORDER);
        foreach ($paths as [, $hex, $opacity, $d]) {
            $edges = [];
            foreach (array_filter(explode('z', $d)) as $subpath) {
                preg_match('/^M(\d+) (\d+)(.*)$/', $subpath, $start);
                [$x, $y0] = [(int) $start[1], (int) $start[2]];
                $y = $y0;
                preg_match_all('/([hv])(-?\d+)/', $start[3], $steps, PREG_SET_ORDER);
                foreach ($steps as [, $axis, $n]) {
                    if ($axis === 'h') {
                        $x += (int) $n;
                        continue;
                    }
                    for ($r = min($y, $y + $n); $r < max($y, $y + $n); $r++) {
                        $edges[$r][] = $x;
                    }
                    $y += (int) $n;
                }
                for ($r = min($y, $y0); $r < max($y, $y0); $r++) {
                    $edges[$r][] = $x;
                }
            }
            foreach ($edges as $row => $xs) {
                sort($xs);
                for ($i = 0; $i + 1 < count($xs); $i += 2) {
                    for ($x = $xs[$i]; $x < $xs[$i + 1]; $x++) {
                        self::assertNull($out[$row * $width + $x], "Pixel $x,$row is drawn twice");
                        $out[$row * $width + $x] = $hex.'/'.($opacity === '' ? '1' : $opacity);
                    }
                }
            }
        }

        return $out;
    }

    private static function expectedRaster(string $path): array
    {
        $png = imagecreatefrompng($path);
        imagepalettetotruecolor($png);
        $pixels = [];
        for ($y = 0; $y < imagesy($png); $y++) {
            for ($x = 0; $x < imagesx($png); $x++) {
                $p = imagecolorat($png, $x, $y);
                $alpha = ($p >> 24) & 127;
                $pixels[] = $alpha === 127 ? null : sprintf('#%06X', $p & 0xFFFFFF).'/'.($alpha === 0 ? '1' : rtrim(rtrim(number_format((127 - $alpha) / 127, 2, '.', ''), '0'), '.'));
            }
        }

        return $pixels;
    }

    public function testSpriteSvgDrawsExactlyTheSpritePixels(): void
    {
        $sprite = SpriteImage::fromFile(__DIR__.'/../fixtures/winterchilla/sprite.png');

        $this->assertSame(self::expectedRaster(__DIR__.'/../fixtures/winterchilla/sprite.png'), self::rasterize($sprite->toSvg(), $sprite->width, $sprite->height));
    }

    public function testSpriteSvgHandlesHolesDiagonalsAndTranslucency(): void
    {
        $png = imagecreatetruecolor(8, 8);
        imagesavealpha($png, true);
        imagealphablending($png, false);
        imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127));
        $red = imagecolorallocate($png, 255, 0, 0);
        $blue = imagecolorallocate($png, 0, 0, 255);
        $ghost = imagecolorallocatealpha($png, 255, 0, 0, 64);
        imagefilledrectangle($png, 0, 0, 5, 5, $red);
        imagefilledrectangle($png, 2, 2, 3, 3, $blue); // a hole in the red shape, filled with another color
        imagesetpixel($png, 6, 6, $red); // touches the red square only at a corner
        imagesetpixel($png, 7, 0, $red);
        imagesetpixel($png, 6, 1, $red); // diagonal pair
        imagesetpixel($png, 0, 7, $ghost);
        imagesetpixel($png, 1, 7, $ghost);
        $path = tempnam(sys_get_temp_dir(), 'sprite').'.png';
        imagepng($png, $path);

        try {
            $sprite = SpriteImage::fromFile($path);
            $svg = $sprite->toSvg();

            $this->assertSame(self::expectedRaster($path), self::rasterize($svg, 8, 8));
            $this->assertSame(3, substr_count($svg, '<path'), 'one path per color and opacity');
        } finally {
            unlink($path);
        }
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
