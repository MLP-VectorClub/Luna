<?php

namespace App\Utils;

class HexColor
{
    /**
     * Uppercase `#RRGGBB` from user input, or null when the input is not a 6 digit hex color
     */
    public static function normalize(string $input): ?string
    {
        return preg_match('/^#?([\dA-Fa-f]{6})$/', trim($input), $match) ? '#'.strtoupper($match[1]) : null;
    }

    /**
     * Snaps nearly black / nearly white components to 0 / 255, official guide colors are rounded like that
     */
    public static function round(string $hex): string
    {
        $components = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        $components = array_map(fn(int $value) => $value <= 3 ? 0 : ($value >= 252 ? 255 : $value), $components);

        return strtoupper(sprintf('#%02x%02x%02x', ...$components));
    }
}
