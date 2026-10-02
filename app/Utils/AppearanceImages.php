<?php

namespace App\Utils;

/**
 * Generated images of appearances, ported from Winterchilla's CGUtils so the output matches byte for byte (see tests/Feature/AppearanceImagesTest.php)
 */
class AppearanceImages
{
    /**
     * The four-color preview: the colors are laid out depending on how many there are and blurred slightly.
     * Same as CGUtils::renderPreviewSVG()
     *
     * @param  string[]  $hexes  up to 4 colors like `#AABBCC`, in preview order
     */
    public static function previewSvg(array $hexes): string
    {
        $hexes = array_values($hexes);
        $count = count($hexes);
        $svg = '';
        switch ($count) {
            case 0:
                $svg .= '<rect fill="#FFFFFF" width="2" height="2"/><rect fill="#EFEFEF" width="1" height="1"/><rect fill="#EFEFEF" width="1" height="1" x="1" y="1"/>';
                break;
            case 1:
                $svg .= "<rect x='0' y='0' width='2' height='2' fill='{$hexes[0]}'/>";
                break;
            case 3:
                $svg .= "<rect x='0' y='0' width='2' height='2' fill='{$hexes[0]}'/>\n<rect x='0' y='1' width='1' height='1' fill='{$hexes[1]}'/>\n<rect x='1' y='1' width='1' height='1' fill='{$hexes[2]}'/>";
                break;
            case 2:
            case 4:
                $x = 0;
                $y = 0;
                foreach ($hexes as $hex) {
                    $w = $x % 2 === 0 ? 2 : 1;
                    $h = $y % 2 === 0 ? 2 : 1;
                    $svg .= "<rect x='$x' y='$y' width='$w' height='$h' fill='$hex'/>";
                    $x++;
                    if ($x > 1) {
                        $x = 0;
                        $y = 1;
                    }
                }
                break;
        }

        // Only apply blur if there are colors
        if ($count > 0) {
            $svg = "<defs><filter id='b' x='0' y='0'><feGaussianBlur in='SourceGraphic' stdDeviation='0.4' /></filter></defs><g filter='url(#b)'>$svg</g>";
        }

        return "<svg version='1.1' xmlns='http://www.w3.org/2000/svg' viewBox='.5 .5 1 1' enable-background='new 0 0 2 2' xml:space='preserve' preserveAspectRatio='xMidYMid slice'>$svg</svg>";
    }

    public const DEFAULT_COLOR_MAPPING = [
        'Coat Outline' => '#0D0D0D',
        'Coat Shadow Outline' => '#000000',
        'Coat Fill' => '#2B2B2B',
        'Coat Shadow Fill' => '#171717',
        'Mane & Tail Outline' => '#333333',
        'Mane & Tail Fill' => '#5E5E5E',
    ];

    /**
     * Picks the colors that the facing graphic uses out of an appearance's colors. Same as Appearance::getColorMapping() in Winterchilla
     *
     * @param  array<int, array{cglabel: string, clabel: string|null, hex: string|null}>  $rows  every color of the appearance with its color group label, ordered by group order, then color label
     * @return array<string, string|null>
     */
    public static function colorMapping(array $rows): array
    {
        $color_mapping = [];
        foreach ($rows as $row) {
            $cglabel = preg_replace('/^(Costume|Dress)$/', 'Coat', $row['cglabel']);
            $cglabel = preg_replace('/^(Coat|Mane & Tail) \([^)]+\)$/', '$1', $cglabel);
            $eye = $row['cglabel'] === 'Iris';
            $eye_regex = !$eye ? '|Gradient(?:\s(?:Light|(?:\d+\s)?(?:Top|Botom)))?\s' : '';
            $colorlabel = preg_replace("~^(?:(?:(?:Purple|Yellow|Red)\\s)?(?:Main|First|Normal{$eye_regex}))?(.+?)(?:\\s\\d+)?(?:/.*)?\$~", '$1', $row['clabel'] ?? '');
            $label = "$cglabel $colorlabel";
            if (isset(self::DEFAULT_COLOR_MAPPING[$label]) && !isset($color_mapping[$label])) {
                $color_mapping[$label] = $row['hex'];
            }
        }
        if (!isset($color_mapping['Coat Shadow Outline']) && isset($color_mapping['Coat Outline'])) {
            $color_mapping['Coat Shadow Outline'] = $color_mapping['Coat Outline'];
        }
        if (!isset($color_mapping['Coat Shadow Fill']) && isset($color_mapping['Coat Fill'])) {
            $color_mapping['Coat Shadow Fill'] = $color_mapping['Coat Fill'];
        }

        return $color_mapping;
    }

    /**
     * The body orientation graphic (cutie mark background) colored with the appearance's colors. Same as CGUtils::renderCMFacingSVG()
     *
     * @param  string  $facing  `left` or `right`
     */
    public static function facingSvg(string $facing, array $rows): string
    {
        $mapping = self::colorMapping($rows);
        $img = file_get_contents(resource_path('images/cm_facing/'.($facing === 'right' ? 'right' : 'left').'.svg'));
        foreach (self::DEFAULT_COLOR_MAPPING as $label => $default_hex) {
            $img = str_replace($label, $mapping[$label] ?? $default_hex, $img);
        }

        return $img;
    }

    /**
     * Reads the sprite pixel by pixel into horizontal lines grouped by color and opacity. Same as CGUtils::getSpriteImageMap(), including its
     * behaviour of continuing a line over into the next color when that color's first pixel directly follows the previous color's last pixel
     * (the line then keeps the previous color), which loses that pixel's color in the traced SVG
     *
     * @return array{width: int, height: int, linedata: array<int, array{x: int, y: int, width: int, colorid: int, opacity: int}>, colors: string[]}
     */
    public static function traceSprite(string $png_path): array
    {
        $size = getimagesize($png_path);
        if ($size === false) {
            throw new \RuntimeException("getimagesize failed to read sprite $png_path");
        }
        [$width, $height] = $size;
        $png = imagecreatefrompng($png_path);
        if ($png === false) {
            throw new \RuntimeException("Could not create image from path $png_path");
        }
        imagesavealpha($png, true);

        $all_colors = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $colors = imagecolorsforindex($png, imagecolorat($png, $x, $y));
                $hex = strtoupper('#'.str_pad(dechex($colors['red']), 2, '0', STR_PAD_LEFT).str_pad(dechex($colors['green']), 2, '0', STR_PAD_LEFT).str_pad(dechex($colors['blue']), 2, '0', STR_PAD_LEFT));
                $opacity = $colors['alpha'] ?? 0;
                if ($opacity === 127) {
                    continue;
                }
                $all_colors[$hex][$opacity][] = [$x, $y];
            }
        }

        $current_line = null;
        $lines = [];
        $last_x = -2;
        $last_y = -2;
        $colors_assoc = [];
        $color_no = 0;
        foreach ($all_colors as $hex => $opacities) {
            if (!isset($colors_assoc[$hex])) {
                $colors_assoc[$hex] = $color_no;
                $color_no++;
            }
            foreach ($opacities as $opacity => $coords) {
                foreach ($coords as [$x, $y]) {
                    if ($x - 1 !== $last_x || $y !== $last_y) {
                        if ($current_line !== null) {
                            $lines[] = $current_line;
                        }
                        $current_line = ['x' => $x, 'y' => $y, 'width' => 1, 'colorid' => $colors_assoc[$hex], 'opacity' => $opacity];
                    } else {
                        $current_line['width']++;
                    }
                    $last_x = $x;
                    $last_y = $y;
                }
            }
        }
        if ($current_line !== null) {
            $lines[] = $current_line;
        }

        return ['width' => $width, 'height' => $height, 'linedata' => $lines, 'colors' => array_flip($colors_assoc)];
    }

    /**
     * Same as the SVG assembly of CGUtils::renderSpriteSVG()
     */
    public static function spriteSvg(array $map): string
    {
        $strokes = [];
        foreach ($map['linedata'] as $line) {
            $hex = $map['colors'][$line['colorid']];
            if ($line['opacity'] !== 0) {
                $opacity = (float) number_format((127 - $line['opacity']) / 127, 2, '.', '');
                $hex .= "' opacity='{$opacity}";
            }
            $strokes[$hex][] = "M{$line['x']} {$line['y']} l{$line['width']} 0Z";
        }
        $svg = "<svg version='1.1' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$map['width']} {$map['height']}' enable-background='new 0 0 {$map['width']} {$map['height']}' xml:space='preserve'>";
        foreach ($strokes as $hex => $defs) {
            $svg .= "<path stroke='$hex' d='".rtrim(implode(' ', $defs))."'/>";
        }

        return $svg.'</svg>';
    }

    /**
     * The Illustrator swatch import file, same as CGUtils::getSwatchesAI(): appearance label, color group, color label to hex
     *
     * @param  array<string, array<string, string>>  $groups  color group label => (color label => hex)
     */
    public static function swatchJson(string $label, array $groups, ?int $exported_at = null): string
    {
        $json = ['Exported at' => gmdate('Y-m-d H:i:s \G\M\T', $exported_at ?? time()), 'Version' => '1.4'];
        $json[$label] = $groups;

        return json_encode($json, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The GIMP / Inkscape palette, same as CGUtils::generateGimpPalette()
     *
     * @param  array<int, array{0: int, 1: int, 2: int, 3: string}>  $colors  red, green, blue, label
     */
    public static function gimpPalette(string $name, array $colors, ?int $exported_at = null): string
    {
        $export_ts = gmdate('Y-m-d H:i:s T', $exported_at ?? time());
        $file = "GIMP Palette\nName: $name\nColumns: 6\n#\n# Exported at: $export_ts\n#\n";
        $file .= implode("\n", array_map(fn(array $color) => implode(' ', [
            str_pad((string) $color[0], 3, ' ', STR_PAD_LEFT),
            str_pad((string) $color[1], 3, ' ', STR_PAD_LEFT),
            str_pad((string) $color[2], 3, ' ', STR_PAD_LEFT),
            htmlspecialchars($color[3]),
        ]), $colors));

        return "$file\n";
    }
}
