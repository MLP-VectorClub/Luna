<?php

namespace App\Utils;

/**
 * Sprites are small pixelated images; their double size version is an exact 2x copy of every pixel (nearest neighbor, like the old site's
 * rendering of sprites), not a smoothed enlargement
 */
class PixelUpscale
{
    public static function double(string $from, string $to): bool
    {
        $info = @getimagesize($from);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return false;
        }

        $source = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($from) : @imagecreatefromjpeg($from);
        if ($source === false) {
            return false;
        }

        $scaled = imagescale($source, $info[0] * 2, $info[1] * 2, IMG_NEAREST_NEIGHBOUR);
        if ($scaled === false) {
            return false;
        }

        if ($info[2] === IMAGETYPE_PNG) {
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);

            return imagepng($scaled, $to);
        }

        return imagejpeg($scaled, $to, 95);
    }
}
