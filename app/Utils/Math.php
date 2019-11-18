<?php

namespace App\Utils;

class Math
{
    public static function greatestCommonDivisor(int $a, int $b):int
    {
        if ($b === 0) {
            return $a;
        }

        return self::greatestCommonDivisor($b, $a % $b);
    }

    /**
     * @param int $width
     * @param int $height
     *
     * @return int[]
     */
    public static function reduceRatio(int $width, int $height):array
    {
        if ($width === $height) {
            return [1, 1];
        }

        if ($width < $height) {
            [$width, $height] = [$height, $width];
        }

        $divisor = self::greatestCommonDivisor($width, $height);

        return [$width / $divisor, $height / $divisor];
    }
}
