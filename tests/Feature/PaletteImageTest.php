<?php

namespace Tests\Feature;

use App\Utils\PaletteImage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The expected images in tests/fixtures/winterchilla/palette were rendered by Winterchilla's CGUtils::renderAppearancePNG() for real appearances
 * (scripts/generate-winterchilla-palette-goldens.php). Given the same input, including the time and URL printed in the header, Luna has to
 * produce exactly the same PNG. `palette` holds what Winterchilla renders today (it never finds the sprite, so none is drawn), `palette-sprite` what it
 * renders when the sprite is found (the way it is meant to work).
 */
class PaletteImageTest extends TestCase
{
    public static function appearances(): array
    {
        $cases = [];
        foreach (['palette' => 'as Winterchilla renders it today', 'palette-sprite' => 'with the sprite'] as $dir => $label) {
            foreach (glob(__DIR__."/../fixtures/winterchilla/$dir/*.json") as $file) {
                $id = basename($file, '.json');
                $cases["appearance $id $label"] = [$dir, $id];
            }
        }

        return $cases;
    }

    #[DataProvider('appearances')]
    public function testPaletteImageMatchesWinterchilla(string $set, string $id): void
    {
        $dir = __DIR__."/../fixtures/winterchilla/$set";
        $meta = json_decode(file_get_contents("$dir/$id.json"), true);

        $png = PaletteImage::render($meta['name'], $meta['sprite'] ? "$dir/$id-sprite.png" : null, $meta['groups'], $meta['generated_at'], $meta['source_url']);

        $expected = file_get_contents("$dir/$id.png");
        $this->assertSame(getimagesizefromstring($expected)[0].'x'.getimagesizefromstring($expected)[1], getimagesizefromstring($png)[0].'x'.getimagesizefromstring($png)[1], 'Dimensions differ');
        $this->assertSame(md5($expected), md5($png), 'The PNG differs from the one Winterchilla rendered');
    }
}
