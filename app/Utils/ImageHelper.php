<?php


namespace App\Utils;

class ImageHelper
{
    public static function getAspectRatio(string $image_file): ?array
    {
        $size = getimagesize($image_file);
        if (!$size) {
            return null;
        }

        [$width, $height] = $size;
        return Math::reduceRatio($width, $height);
    }
}
