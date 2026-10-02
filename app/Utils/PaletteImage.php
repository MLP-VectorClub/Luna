<?php

namespace App\Utils;

use GdImage;
use RuntimeException;

/**
 * The palette image of an appearance (sprite, name and every color with its label), ported from Winterchilla's CGUtils::renderAppearancePNG()
 * and the GD helpers in its Image class, so the output matches pixel for pixel when given the same input (see tests/Feature/PaletteImageTest.php)
 */
class PaletteImage
{
    private const REGULAR_FONT = 'Celestia Redux Alternate.ttf';
    private const PIXELATED_FONT = 'PixelOperator.ttf';

    /**
     * @param  string|null  $sprite_path  path of the sprite PNG, if the appearance has one
     * @param  array<int, array{label: string, colors: array<int, array{label: string, hex: string|null}>}>  $groups
     * @param  string  $generated_at  shown in the header, Winterchilla uses the full date and time of the export
     * @param  string  $source_url  shown in the header, the URL of the appearance
     * @return string PNG data
     */
    public static function render(string $name, ?string $sprite_path, array $groups, string $generated_at, string $source_url): string
    {
        $sprite_right_margin = 10;
        $color_circle_size = 17;
        $color_circle_right_margin = 5;
        $color_name_font_size = 12;
        $regular_font_file = resource_path('fonts/'.self::REGULAR_FONT);
        $pixelated_font_file = resource_path('fonts/'.self::PIXELATED_FONT);
        $name_vertical_margin = 5;
        $name_font_size = 22;
        $text_margin = 10;
        $output_color_count = 0;
        $split_threshold = 12;
        $column_right_margin = 20;
        $sprite_width = 0;
        $sprite_height = 0;
        $output_height = 0;

        $sprite_exists = $sprite_path !== null && file_exists($sprite_path);
        if ($sprite_exists) {
            $sprite_size = getimagesize($sprite_path);
            if ($sprite_size === false) {
                throw new RuntimeException("The sprite image located at $sprite_path could not be loaded by getimagesize");
            }
            $sprite_image = imagecreatefrompng($sprite_path);
            [$sprite_width, $sprite_height] = $sprite_size;
            $sprite_outer_width = $sprite_width + $sprite_right_margin;
            $output_height = $sprite_height;
        } else {
            $sprite_outer_width = 0;
        }
        $origin = ['x' => $sprite_exists ? $sprite_outer_width : $text_margin, 'y' => 0];

        $cg_font_size = (int) round($name_font_size * 0.75);
        $cg_vertical_margin = $name_vertical_margin;
        $test_string = 'ABCDEFGIJKLMOPQRSTUVWQYZabcdefghijklmnopqrstuvwxyz/()}{@&#><';
        $group_label_box = self::ttfBox($cg_font_size, $regular_font_file, $test_string);

        $export_ts = ['Generated at: '.$generated_at, 'Source: '.$source_url];
        $export_font_size = (int) round($cg_font_size * 0.7);
        $export_box = self::ttfBox($export_font_size, $pixelated_font_file, $export_ts);

        $repost_warning = [
            'Please do not re-post this image on other sites to avoid spreading a',
            'particular version around that could become out of date in the future.',
        ];
        $repost_font_size = (int) round($cg_font_size * 0.6);
        $repost_box = self::ttfBox($repost_font_size, $pixelated_font_file, $repost_warning);

        $name_box = self::ttfBox($name_font_size, $regular_font_file, $name);
        $output_width = $origin['x'] + max($name_box['width'], $export_box['width'], $repost_box['width']) + $text_margin;
        $output_height = max($origin['y'] + (($name_vertical_margin * 4) + $name_box['height'] + $export_box['height'] + $repost_box['height']), $output_height);

        $base_image = self::transparent($output_width, $output_height);
        $c_black = imagecolorallocate($base_image, 0, 0, 0);
        $c_dark_red = imagecolorallocate($base_image, 127, 0, 0);

        if ($sprite_exists) {
            self::copyExact($base_image, $sprite_image, 0, 0, $sprite_width, $sprite_height);
        }

        $origin['y'] += $name_vertical_margin * 2;
        self::writeOn($base_image, $name, $origin['x'], $name_font_size, $c_black, $origin, $regular_font_file);
        $origin['y'] += $name_vertical_margin;

        self::writeOn($base_image, $export_ts, $origin['x'], $export_font_size, $c_black, $origin, $pixelated_font_file);
        $origin['y'] += $name_vertical_margin;

        self::writeOn($base_image, $repost_warning, $origin['x'], $repost_font_size, $c_dark_red, $origin, $pixelated_font_file);
        $origin['y'] += $name_vertical_margin * 2;

        if ($groups !== []) {
            $cg_start_y = $origin['y'];
            $cg_largest_x = 0;
            foreach ($groups as $group) {
                $cg_label_box = self::ttfBox($cg_font_size, $regular_font_file, $group['label']);
                self::redraw($output_width, $output_height, $cg_label_box['width'] + $text_margin, $group_label_box['height'] + $name_vertical_margin + $cg_vertical_margin, $base_image, $origin);
                self::writeOn($base_image, $group['label'], $origin['x'], $cg_font_size, $c_black, $origin, $regular_font_file, $group_label_box);
                $origin['y'] += $group_label_box['height'] + $cg_vertical_margin;

                if ($cg_label_box['width'] > $cg_largest_x) {
                    $cg_largest_x = $cg_label_box['width'];
                }

                if ($group['colors'] !== []) {
                    $y_offset = -1;
                    foreach ($group['colors'] as $color) {
                        $color_name_left_offset = $color_circle_size + $color_circle_right_margin;
                        $color_name_box = self::ttfBox($color_name_font_size, $regular_font_file, $color['label']);

                        $width_increase = $color_name_left_offset + $color_name_box['width'] + $text_margin;
                        $height_increase = max($color_circle_size, $color_name_box['height']) + $cg_vertical_margin;
                        self::redraw($output_width, $output_height, $width_increase, $height_increase, $base_image, $origin);

                        self::circle($base_image, $origin['x'], $origin['y'], [$color_circle_size, $color_circle_size], $color['hex'], $c_black);

                        self::writeOn($base_image, $color['label'], $origin['x'] + $color_name_left_offset, $color_name_font_size, $c_black, $origin, $regular_font_file, $color_name_box, $y_offset);
                        $origin['y'] += $height_increase;

                        $output_color_count++;

                        $total_width = $color_name_left_offset + $color_name_box['width'];
                        if ($total_width > $cg_largest_x) {
                            $cg_largest_x = $total_width;
                        }
                    }
                }

                if ($output_color_count > $split_threshold) {
                    self::redraw($output_width, $output_height, 0, $name_vertical_margin, $base_image, $origin);
                    $origin['y'] = $cg_start_y;
                    $origin['x'] += $cg_largest_x + $column_right_margin;
                    $output_color_count = 0;
                    $cg_largest_x = 0;
                } else {
                    $origin['y'] += $name_vertical_margin;
                }
            }
        }

        $final_base = imagecreatetruecolor($output_width, $output_height);
        imagefill($final_base, 0, 0, imagecolorallocate($final_base, 255, 255, 255));
        imagerectangle($final_base, 0, 0, $output_width - 1, $output_height - 1, imagecolorallocate($final_base, 0, 0, 0));
        self::copyExact($final_base, $base_image, 0, 0, $output_width, $output_height);

        ob_start();
        imagepng($final_base, null, 9, PNG_NO_FILTER);

        return ob_get_clean();
    }

    private static function transparent(int $width, int $height): GdImage
    {
        $png = imagecreatetruecolor($width, $height);
        $alpha = imagecolorallocatealpha($png, 0, 0, 0, 127);
        imagecolortransparent($png, $alpha);
        imagealphablending($png, false);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, $alpha);

        return $png;
    }

    private static function copyExact(GdImage $dest, GdImage $source, int $x, int $y, int $w, int $h): void
    {
        imagecopyresampled($dest, $source, $x, $y, $x, $y, $w, $h, $w, $h);
    }

    private static function circle(GdImage $image, int $x, int $y, array $size, ?string $fill, int $outline): void
    {
        $fill_color = null;
        if ($fill !== null && preg_match('/^#?([\da-f]{6}|[\da-f]{3})$/i', $fill, $match)) {
            $hex = strlen($match[1]) === 3 ? preg_replace('/(.)/', '$1$1', $match[1]) : $match[1];
            $fill_color = imagecolorallocate($image, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        }
        [$width, $height] = $size;
        $cx = (int) (array_sum([$x, $x + $width]) / 2);
        $cy = (int) (array_sum([$y, $y + $height]) / 2);
        if ($fill_color !== null) {
            imagefilledellipse($image, $cx, $cy, $width, $height, $fill_color);
        }
        imageellipse($image, $cx, $cy, $width, $height, $outline);
    }

    /**
     * Grows the base image when the next element does not fit, same as Image::calcRedraw()
     */
    private static function redraw(int &$out_width, int &$out_height, int $width_increase, int $height_increase, GdImage &$base_image, array $origin): void
    {
        $redraw = false;
        if ($origin['x'] + $width_increase > $out_width) {
            $redraw = true;
            $origin['x'] += $width_increase;
        }
        if ($origin['y'] + $height_increase > $out_height) {
            $redraw = true;
            $origin['y'] += $height_increase;
        }
        if ($redraw) {
            $new_width = max($origin['x'], $out_width);
            $new_height = max($origin['y'], $out_height);
            $new_image = self::transparent($new_width, $new_height);
            self::copyExact($new_image, $base_image, 0, 0, $out_width, $out_height);
            $base_image = $new_image;
            $out_width = $new_width;
            $out_height = $new_height;
        }
    }

    /**
     * @param  string|string[]  $text
     */
    private static function writeOn(GdImage $image, string|array $text, int $x, int $font_size, int $font_color, array &$origin, string $font_file, ?array $box = null, int $y_offset = 0): void
    {
        $line_count = is_array($text) ? count($text) : 1;
        $line_padding_bottom = 2;
        if (empty($box)) {
            $box = self::ttfBox($font_size, $font_file, $text);
            $origin['y'] += $box['height'];
            $y = $origin['y'] - $box['bottom right']['y'];
        } else {
            $y = $origin['y'] + $box['height'] - $box['bottom right']['y'];
        }

        if ($line_count === 1) {
            imagettftext($image, $font_size, 0, $x, $y + $y_offset, $font_color, $font_file, is_array($text) ? $text[0] : $text);
        } else {
            $y += $y_offset;
            foreach ($text as $line) {
                imagettftext($image, $font_size, 0, $x, $y, $font_color, $font_file, $line);
                $y += self::lineBox($font_size, $font_file, $line)['height'] + $line_padding_bottom;
            }
        }
    }

    private static function lineBox(int $font_size, string $font_file, string $line): array
    {
        $box = imagettfbbox($font_size, 0, $font_file, $line);
        $result = [
            'bottom left' => ['x' => $box[0], 'y' => $box[1]],
            'bottom right' => ['x' => $box[2], 'y' => $box[3]],
            'top right' => ['x' => $box[4], 'y' => $box[5]],
            'top left' => ['x' => $box[6], 'y' => $box[7]],
        ];
        $result['width'] = abs($result['bottom right']['x'] - $result['top left']['x']);
        $result['height'] = abs($result['bottom right']['y'] - $result['top left']['y']);

        return $result;
    }

    /**
     * Same as Image::saneGetTTFBox(): the box of the first line, grown by the following lines
     *
     * @param  string|string[]  $text
     */
    private static function ttfBox(int $font_size, string $font_file, string|array $text): array
    {
        if (!is_array($text)) {
            $first_line = $text;
            $text = [];
        } else {
            $first_line = array_splice($text, 0, 1)[0];
        }

        $box = self::lineBox($font_size, $font_file, $first_line);
        foreach ($text as $line) {
            $line_box = self::lineBox($font_size, $font_file, $line);
            $box['height'] += $line_box['height'];
            $box['bottom left']['y'] += $line_box['height'];
            $box['bottom right']['y'] += $line_box['height'];
            if ($line_box['width'] > $box['width']) {
                $box['width'] = $line_box['width'];
            }
            if ($line_box['top right']['x'] > $box['top right']['x']) {
                $box['top right']['x'] = $line_box['top right']['x'];
                $box['bottom right']['x'] = $line_box['bottom right']['x'];
            }
        }

        return $box;
    }
}
