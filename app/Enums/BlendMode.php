<?php

namespace App\Enums;

/**
 * How a semi-transparent color layer combines with what is under it, the separable blend modes of CSS and of most drawing programs
 */
enum BlendMode: string
{
    use ValuableEnum;

    case Normal = 'normal';
    case Multiply = 'multiply';
    case Screen = 'screen';
    case Overlay = 'overlay';
    case Darken = 'darken';
    case Lighten = 'lighten';
    case ColorDodge = 'color-dodge';
    case ColorBurn = 'color-burn';
    case HardLight = 'hard-light';
    case SoftLight = 'soft-light';
    case Difference = 'difference';
    case Exclusion = 'exclusion';

    /**
     * One channel of the layer over an opaque backdrop: `$base` is the backdrop, `$layer` the color of the layer, both 0 to 255, and the opacity
     * of the layer 0 to 1
     */
    public function channel(float $base, float $layer, float $opacity = 1.0): float
    {
        $b = $base / 255;
        $s = $layer / 255;
        $mixed = match ($this) {
            self::Normal => $s,
            self::Multiply => $b * $s,
            self::Screen => $b + $s - $b * $s,
            self::Overlay => self::HardLight->channel($layer, $base) / 255,
            self::Darken => min($b, $s),
            self::Lighten => max($b, $s),
            self::ColorDodge => $b == 0.0 ? 0.0 : ($s >= 1.0 ? 1.0 : min(1.0, $b / (1 - $s))),
            self::ColorBurn => $b >= 1.0 ? 1.0 : ($s <= 0.0 ? 0.0 : 1 - min(1.0, (1 - $b) / $s)),
            self::HardLight => $s <= 0.5 ? $b * 2 * $s : (static fn() => $b + (2 * $s - 1) - $b * (2 * $s - 1))(),
            self::SoftLight => $s <= 0.5
                ? $b - (1 - 2 * $s) * $b * (1 - $b)
                : $b + (2 * $s - 1) * (($b <= 0.25 ? ((16 * $b - 12) * $b + 4) * $b : sqrt($b)) - $b),
            self::Difference => abs($b - $s),
            self::Exclusion => $b + $s - 2 * $b * $s,
        };

        return 255 * ((1 - $opacity) * $b + $opacity * $mixed);
    }
}
