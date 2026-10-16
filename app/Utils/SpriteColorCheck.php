<?php

namespace App\Utils;

use App\Enums\BlendMode;

/**
 * Compares the colors used by a sprite with the colors listed for its appearance
 *
 * A sprite is a flattened image, so a color in it does not have to be listed as-is: soft edges and glows are the result of blending
 * listed colors (normal mix, multiply, screen), and a color that only appears semi-transparent is drawn over something else, so it
 * cannot be judged by what it looks like. Only colors that nothing explains are reported as unknown.
 */
class SpriteColorCheck
{
    /** Distance in RGB at which an unlisted color is most likely a slightly wrong version of a listed one */
    private const NEAR = 6.0;

    /** Distance in RGB a blend of two listed colors may be off by (rounding in the editor that flattened it) */
    private const BLEND_TOLERANCE = 2.0;

    /** Unlisted colors on fewer pixels than this are stray specks (soft edges, editor noise), reported separately from real mistakes */
    private const SPECK = 5;

    /** Colors listed on every guide appearance, e.g. the mannequin ones, which sprites may use without the appearance repeating them */
    public const SHARED_HEXES = ['#D8D8D8', '#E6E6E6', '#BFBFBF', '#CCCCCC'];

    /**
     * @param  array<string, array<int, int>>  $counts  {@see SpriteImage::colorCounts()}
     * @param  array<int, array{group: string, label: string, hex: string, opacity?: ?int, blendMode?: ?string}>  $palette  colors of the appearance
     * @param  string[]  $shared  hex values that are fine to use without being listed
     * @return array{
     *   palette: array<int, array{group: string, label: string, hex: string, opacity: ?int, blendMode: ?string, status: string, optional: bool, pixels: int}>,
     *   colors: array<int, array{hex: string, pixels: int, translucentPixels: int, kind: string, labels: string[], nearest: ?array{hex: string, label: string, distance: float}, blendOf: ?string[], blendMode: ?string, blendOpacity: ?int}>,
     *   summary: array{missing: int, translucentOnly: int, near: int, unknown: int, specks: int, ok: bool}
     * }
     */
    public static function check(array $counts, array $palette, array $shared = self::SHARED_HEXES): array
    {
        $listed = [];
        foreach ($palette as $color) {
            $listed[strtoupper($color['hex'])][] = $color['group'].' | '.$color['label'];
        }
        $known = $listed;
        foreach ($shared as $hex) {
            $known[strtoupper($hex)] ??= ['(shared)'];
        }
        $rgb = array_map(self::rgb(...), array_combine(array_keys($known), array_keys($known)));
        $used = array_filter($counts, fn($alphas) => array_sum($alphas) > 0);
        $opaqueParents = array_intersect_key($rgb, array_filter($used, fn($alphas) => isset($alphas[0])));

        $colors = [];
        foreach ($counts as $hex => $alphas) {
            $opaque = $alphas[0] ?? 0;
            $translucent = array_sum($alphas) - $opaque;
            $entry = [
                'hex' => $hex,
                'pixels' => $opaque,
                'translucentPixels' => $translucent,
                'kind' => 'unknown',
                'labels' => [],
                'nearest' => null,
                'blendOf' => null,
                'blendMode' => null,
                'blendOpacity' => null,
            ];
            if (isset($known[$hex])) {
                $entry['kind'] = 'listed';
                $entry['labels'] = $known[$hex];
            } elseif ($opaque === 0) {
                // Only drawn semi-transparent: what shows through depends on what is underneath, and edges are full of in-between values
                $entry['kind'] = 'translucent';
            } else {
                $point = self::rgb($hex);
                $nearest = null;
                foreach ($rgb as $other => $value) {
                    $distance = self::distance($point, $value);
                    if ($nearest === null || $distance < $nearest['distance']) {
                        $nearest = ['hex' => $other, 'label' => $known[$other][0], 'distance' => round($distance, 1)];
                    }
                }
                $declared = self::explainDeclared($point, $palette, $opaqueParents);
                $blend = self::explainBlend($point, $opaqueParents);
                $entry['nearest'] = $nearest;
                if ($declared !== null) {
                    $entry['kind'] = 'blend';
                    [$entry['blendOf'], $entry['blendMode'], $entry['blendOpacity']] = $declared;
                    $entry['nearest'] = null;
                } elseif ($nearest !== null && $nearest['distance'] <= self::NEAR) {
                    $entry['kind'] = 'near';
                } elseif ($blend !== null) {
                    $entry['kind'] = 'blend';
                    [$entry['blendOf'], $entry['blendMode']] = $blend;
                    $entry['nearest'] = null;
                }
                if (in_array($entry['kind'], ['near', 'unknown'], true) && $opaque < self::SPECK) {
                    $entry['kind'] .= 'Speck';
                }
            }
            $colors[] = $entry;
        }
        usort($colors, fn($a, $b) => ($b['pixels'] + $b['translucentPixels']) <=> ($a['pixels'] + $a['translucentPixels']));

        $rows = [];
        $summary = ['missing' => 0, 'translucentOnly' => 0, 'near' => 0, 'unknown' => 0, 'specks' => 0, 'ok' => true];
        foreach ($palette as $color) {
            $hex = strtoupper($color['hex']);
            $opaque = $counts[$hex][0] ?? 0;
            $translucent = isset($counts[$hex]) ? array_sum($counts[$hex]) - $opaque : 0;
            // A color that is meant to be semi-transparent is expected to show up that way
            $status = $opaque > 0 ? 'present' : ($translucent > 0 ? (($color['opacity'] ?? null) !== null ? 'present' : 'translucent') : 'missing');
            // Colors for other forms of the character ("(Younger)", "(if used)") are not expected in every sprite
            $optional = (bool) preg_match('/\(.*\)/', $color['group'].' '.$color['label']);
            $rows[] = ['group' => $color['group'], 'label' => $color['label'], 'hex' => $hex, 'opacity' => $color['opacity'] ?? null, 'blendMode' => self::declaredMode($color)?->value, 'status' => $status, 'optional' => $optional, 'pixels' => $opaque + $translucent];
            if ($status === 'missing' && !$optional) {
                $summary['missing']++;
            }
            if ($status === 'translucent' && !$optional) {
                $summary['translucentOnly']++;
            }
        }
        foreach ($colors as $color) {
            if (str_ends_with($color['kind'], 'Speck')) {
                $summary['specks']++;
            } elseif (isset($summary[$color['kind']])) {
                $summary[$color['kind']]++;
            }
        }
        $summary['ok'] = $summary['missing'] + $summary['near'] + $summary['unknown'] === 0;

        return ['palette' => $rows, 'colors' => $colors, 'summary' => $summary];
    }

    private static function declaredMode(array $color): ?BlendMode
    {
        if (($color['opacity'] ?? null) === null && ($color['blendMode'] ?? null) === null) {
            return null;
        }

        // Normal unless it says otherwise
        return BlendMode::tryFrom((string) ($color['blendMode'] ?? '')) ?? BlendMode::Normal;
    }

    /**
     * Colors with an opacity or blend mode are drawn over something, so an opaque color of the sprite can be that color over another one
     *
     * @param  float[]  $point
     * @param  array<int, array<string, mixed>>  $palette
     * @param  array<string, float[]>  $bases  opaque colors of the sprite
     * @return array{0: string[], 1: string, 2: ?int}|null the layer and the base it is over, the blend mode and the opacity
     */
    private static function explainDeclared(array $point, array $palette, array $bases): ?array
    {
        foreach ($palette as $color) {
            $mode = self::declaredMode($color);
            if ($mode === null) {
                continue;
            }
            $layer = self::rgb(strtoupper($color['hex']));
            $opacity = ($color['opacity'] ?? 100) / 100;
            foreach ($bases as $baseHex => $base) {
                $result = [
                    $mode->channel($base[0], $layer[0], $opacity),
                    $mode->channel($base[1], $layer[1], $opacity),
                    $mode->channel($base[2], $layer[2], $opacity),
                ];
                if (self::distance($point, $result) <= self::BLEND_TOLERANCE) {
                    return [[strtoupper($color['hex']), $baseHex], $mode->value, $color['opacity'] ?? 100];
                }
            }
        }

        return null;
    }

    /**
     * @param  float[]  $point
     * @param  array<string, float[]>  $parents
     * @return array{0: string[], 1: string}|null
     */
    private static function explainBlend(array $point, array $parents): ?array
    {
        $hexes = array_keys($parents);
        $count = count($hexes);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $parents[$hexes[$i]];
                $b = $parents[$hexes[$j]];
                $pair = [$hexes[$i], $hexes[$j]];
                [$distance, $t] = self::distanceToSegment($point, $a, $b);
                if ($distance <= self::BLEND_TOLERANCE && $t > 0.02 && $t < 0.98) {
                    return [$pair, 'mix'];
                }
                $multiply = [$a[0] * $b[0] / 255, $a[1] * $b[1] / 255, $a[2] * $b[2] / 255];
                if (self::distance($point, $multiply) <= self::BLEND_TOLERANCE) {
                    return [$pair, 'multiply'];
                }
                $screen = [255 - (255 - $a[0]) * (255 - $b[0]) / 255, 255 - (255 - $a[1]) * (255 - $b[1]) / 255, 255 - (255 - $a[2]) * (255 - $b[2]) / 255];
                if (self::distance($point, $screen) <= self::BLEND_TOLERANCE) {
                    return [$pair, 'screen'];
                }
            }
        }

        return null;
    }

    /** @return float[] */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /** @return array{0: float, 1: float} distance to the closest point of the segment, and where on it that point is (0 to 1) */
    private static function distanceToSegment(array $p, array $a, array $b): array
    {
        $ab = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
        $length = $ab[0] ** 2 + $ab[1] ** 2 + $ab[2] ** 2;
        if ($length == 0) {
            return [self::distance($p, $a), 0.0];
        }
        $t = max(0, min(1, (($p[0] - $a[0]) * $ab[0] + ($p[1] - $a[1]) * $ab[1] + ($p[2] - $a[2]) * $ab[2]) / $length));

        return [self::distance($p, [$a[0] + $t * $ab[0], $a[1] + $t * $ab[1], $a[2] + $t * $ab[2]]), $t];
    }
}
