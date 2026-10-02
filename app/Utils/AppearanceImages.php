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
}
