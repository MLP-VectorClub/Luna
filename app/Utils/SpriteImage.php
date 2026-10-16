<?php

namespace App\Utils;

use RuntimeException;

/**
 * A sprite PNG read into memory: converts it to a compact SVG and reports which colors it uses
 */
class SpriteImage
{
    /** @var int[] row-major, `alpha << 24 | rgb`, alpha being 0 (opaque) to 127 (fully transparent) like GD reports it */
    private array $pixels;

    private function __construct(public readonly int $width, public readonly int $height, array $pixels)
    {
        $this->pixels = $pixels;
    }

    public static function fromFile(string $path): self
    {
        $png = @imagecreatefrompng($path);
        if ($png === false) {
            throw new RuntimeException("Could not create image from path $path");
        }
        imagepalettetotruecolor($png);
        $width = imagesx($png);
        $height = imagesy($png);
        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixels[] = imagecolorat($png, $x, $y);
            }
        }

        return new self($width, $height, $pixels);
    }

    /**
     * @return array<string, array<int, int>> hex => (GD alpha => number of pixels), fully transparent pixels left out
     */
    public function colorCounts(): array
    {
        $counts = [];
        foreach ($this->pixels as $pixel) {
            $alpha = ($pixel >> 24) & 0x7F;
            if ($alpha === 127) {
                continue;
            }
            $hex = sprintf('#%06X', $pixel & 0xFFFFFF);
            $counts[$hex][$alpha] = ($counts[$hex][$alpha] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Every color (and opacity) becomes one path made of the outlines of its connected areas, so neighbouring pixels of the same color are
     * merged into a single shape instead of being drawn one by one. Holes are cut with the even-odd rule. The result is pixel-exact.
     */
    public function toSvg(): string
    {
        $layers = [];
        foreach ($this->pixels as $i => $pixel) {
            $alpha = ($pixel >> 24) & 0x7F;
            if ($alpha === 127) {
                continue;
            }
            $layers[sprintf('#%06X', $pixel & 0xFFFFFF)][$alpha][] = $i;
        }

        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$this->width} {$this->height}' shape-rendering='crispEdges'>";
        foreach ($layers as $hex => $alphas) {
            foreach ($alphas as $alpha => $indexes) {
                $opacity = $alpha === 0 ? '' : " opacity='".rtrim(rtrim(number_format((127 - $alpha) / 127, 2, '.', ''), '0'), '.')."'";
                $svg .= "<path fill='$hex'$opacity fill-rule='evenodd' d='".self::outline($indexes, $this->width)."'/>";
            }
        }

        return $svg.'</svg>';
    }

    /**
     * @param  int[]  $indexes  pixel indexes of one layer
     */
    private static function outline(array $indexes, int $width): string
    {
        $in = array_fill_keys($indexes, true);
        $stride = $width + 1;
        // Clockwise unit edges around every pixel side that touches something else; edges of neighbours cancel out by not being created
        $next = [];
        foreach ($indexes as $i) {
            $x = $i % $width;
            $y = intdiv($i, $width);
            $tl = $y * $stride + $x;
            if (!isset($in[$i - $width]) || $y === 0) {
                $next[$tl][] = $tl + 1;
            }
            if (!isset($in[$i + 1]) || $x === $width - 1) {
                $next[$tl + 1][] = $tl + 1 + $stride;
            }
            if (!isset($in[$i + $width])) {
                $next[$tl + 1 + $stride][] = $tl + $stride;
            }
            if (!isset($in[$i - 1]) || $x === 0) {
                $next[$tl + $stride][] = $tl;
            }
        }

        $d = '';
        while ($next !== []) {
            $start = array_key_first($next);
            $loop = [$start];
            $at = $start;
            do {
                $to = array_pop($next[$at]);
                if ($next[$at] === []) {
                    unset($next[$at]);
                }
                $at = $to;
                $loop[] = $at;
            } while ($at !== $start);
            array_pop($loop);

            // Keep only the corners
            $n = count($loop);
            $corners = [];
            for ($k = 0; $k < $n; $k++) {
                $prev = $loop[($k + $n - 1) % $n];
                $cur = $loop[$k];
                $nxt = $loop[($k + 1) % $n];
                if (($cur - $prev) !== ($nxt - $cur)) {
                    $corners[] = $cur;
                }
            }
            $first = $corners[0];
            $d .= 'M'.($first % $stride).' '.intdiv($first, $stride);
            $px = $first % $stride;
            $py = intdiv($first, $stride);
            for ($k = 1, $c = count($corners); $k <= $c; $k++) {
                $v = $corners[$k % $c];
                $vx = $v % $stride;
                $vy = intdiv($v, $stride);
                if ($k === $c) {
                    break;
                }
                $d .= $vy === $py ? 'h'.($vx - $px) : 'v'.($vy - $py);
                $px = $vx;
                $py = $vy;
            }
            $d .= 'z';
        }

        return $d;
    }
}
