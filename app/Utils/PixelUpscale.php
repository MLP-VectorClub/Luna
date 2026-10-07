<?php

namespace App\Utils;

/**
 * Sprites are small pixelated images; their double size version is an exact 2x copy of every pixel (nearest neighbor, like the old site's
 * rendering of sprites), not a smoothed enlargement
 */
class PixelUpscale
{
    /**
     * Writes the doubled copy of `$from` to `$to`. The result is made next to the target and moved over it only once it is complete and has the right size,
     * so a failure never leaves a half written or wrong sized sprite behind
     */
    public static function double(string $from, string $to): bool
    {
        return self::tryDouble($from, $to) === null;
    }

    /**
     * @return string|null why it did not work, null when it did
     */
    public static function tryDouble(string $from, string $to): ?string
    {
        $info = @getimagesize($from);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return "$from is not a PNG or JPEG image";
        }
        $dir = dirname($to);
        if (!is_dir($dir) || !is_writable($dir)) {
            return "the folder $dir is not writable for ".self::user();
        }
        if (is_file($to) && !is_writable($to)) {
            return "$to is not writable for ".self::user();
        }

        $source = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($from) : @imagecreatefromjpeg($from);
        if ($source === false) {
            return "$from could not be read as an image";
        }
        $scaled = imagescale($source, $info[0] * 2, $info[1] * 2, IMG_NEAREST_NEIGHBOUR);
        if ($scaled === false) {
            return 'the image could not be enlarged';
        }

        $temporary = $dir.'/.'.basename($to).'.'.getmypid().'.tmp';
        try {
            if ($info[2] === IMAGETYPE_PNG) {
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                $written = @imagepng($scaled, $temporary);
            } else {
                $written = @imagejpeg($scaled, $temporary, 95);
            }
            $size = $written ? @getimagesize($temporary) : false;
            if (!$written || $size === false || $size[0] !== $info[0] * 2 || $size[1] !== $info[1] * 2) {
                return "the doubled image could not be written to $dir";
            }
            // The mode of the file it replaces (the web server and the deploy user share these folders through the group)
            @chmod($temporary, is_file($to) ? (fileperms($to) & 0777) : 0664);
            if (!@rename($temporary, $to)) {
                return "$to could not be replaced";
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return null;
    }

    private static function user(): string
    {
        $info = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;

        return $info ? "the user {$info['name']}" : 'this user';
    }
}
