<?php

namespace App\Utils;

use DOMDocument;
use DOMElement;
use enshrined\svgSanitize\Sanitizer;
use enshrined\svgSanitize\data\AllowedAttributes;
use enshrined\svgSanitize\data\AttributeInterface;
use enshrined\svgSanitize\data\TagInterface;
use SeinopSys\RGBAColor;

/**
 * Sanitizing for user uploaded SVG files (cutie marks), mirrors Winterchilla's `CoreUtils::sanitizeSvg`.
 * Unlike Winterchilla it does not run svgo, the sanitizer's own minifier is used instead.
 */
class SvgHelper
{
    public static function uncompress(string $data): string
    {
        if (str_starts_with($data, "\x1f\x8b\x08")) {
            $decoded = @gzdecode($data);
            if (is_string($decoded)) {
                return $decoded;
            }
        }

        return $data;
    }

    /**
     * @param  string[]|null  $warnings  filled with problems the uploader should know about
     * @return string|null null when the data is not a usable SVG document
     */
    public static function sanitize(string $dirty_svg, ?array &$warnings = null): ?string
    {
        $dirty_svg = preg_replace('/&ns_[a-z_]+;/', '', self::uncompress($dirty_svg));
        if ($dirty_svg === null || trim($dirty_svg) === '') {
            return null;
        }

        $sanitizer = new Sanitizer();
        $sanitizer->setAllowedTags(new class implements TagInterface {
            public static function getTags(): array
            {
                return [
                    'svg', 'circle', 'clippath', 'clipPath', 'defs', 'ellipse', 'filter', 'font', 'g', 'line',
                    'lineargradient', 'marker', 'mask', 'mpath', 'path', 'pattern', 'style',
                    'polygon', 'polyline', 'radialgradient', 'rect', 'stop', 'switch', 'use', 'view',
                    'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite',
                    'feconvolvematrix', 'fediffuselighting', 'fedisplacementmap',
                    'feflood', 'fefunca', 'fefuncb', 'fefuncg', 'fefuncr', 'fegaussianblur',
                    'femerge', 'femergenode', 'femorphology', 'feoffset',
                    'fespecularlighting', 'fetile', 'feturbulence',
                ];
            }
        });
        $sanitizer->setAllowedAttrs(new class implements AttributeInterface {
            public static function getAttributes(): array
            {
                $allowed = array_flip(AllowedAttributes::getAttributes());
                unset($allowed['color']);

                return array_keys($allowed);
            }
        });
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->minify(true);
        $sanitized = $sanitizer->sanitize($dirty_svg);
        if (!is_string($sanitized) || $sanitized === '') {
            return null;
        }

        $unifier = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $unifier->loadXML($sanitized);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $unifier->documentElement === null
            || strtolower($unifier->documentElement->nodeName) !== 'svg'
            || $unifier->documentElement->childNodes->length === 0) {
            return null;
        }

        if ($warnings !== null) {
            foreach ($unifier->getElementsByTagName('*') as $tag) {
                /** @var DOMElement $tag */
                if ($tag->getAttribute('transform') !== '') {
                    $warnings[] = 'File contains one or more transform attributes (extremely likely to cause incorrect rendering in Illustrator)';
                    break;
                }
            }
        }

        // Single-stop linear gradients break in Illustrator, duplicate the stop
        $single_stop_warnings = [];
        foreach (iterator_to_array($unifier->getElementsByTagName('linearGradient')) as $gradient) {
            /** @var DOMElement $gradient */
            if ($gradient->childNodes->length !== 1 || !($stop = $gradient->childNodes->item(0)) instanceof DOMElement) {
                continue;
            }
            $color = $stop->getAttribute('stop-color');
            $copy = $stop->cloneNode();
            $copy->setAttribute('offset', (string) (1 - (float) $stop->getAttribute('offset')));
            $copy->setAttribute('stop-color', $color);
            $gradient->appendChild($copy);
            $single_stop_warnings[$color] = "Single-stop linear gradient found with color $color which will break in Illustrator (this has been remedied by duplicating the color stop, but fixing the source file would be ideal)";
        }
        if ($warnings !== null) {
            $warnings = array_merge($warnings, array_values($single_stop_warnings));
        }

        return $unifier->saveXML($unifier->documentElement, LIBXML_NOEMPTYTAG) ?: null;
    }

    /**
     * Warnings for colors that are not part of the given hex list (`#rrggbb`, lowercase)
     *
     * @param  string[]  $known_hexes
     * @return string[]
     */
    public static function unknownColorWarnings(string $svg, array $known_hexes): array
    {
        $warnings = [];
        preg_match_all('/#([\da-f]{8}|[\da-f]{6}|[\da-f]{3})\b/i', $svg, $matches);
        foreach (array_unique(array_map('strtolower', $matches[1])) as $hex) {
            if (strlen($hex) === 3) {
                $hex = preg_replace('/(.)/', '$1$1', $hex);
            }
            $rgb = '#'.substr($hex, 0, 6);
            if (!in_array($rgb, $known_hexes, true)) {
                $warnings[strtoupper($rgb)] = 'Unexpected color '.strtoupper($rgb).' (not found in Cutie Mark color group)';
            }
        }

        return array_values($warnings);
    }

    /**
     * Replaces every color of the SVG by a comment that points at the guide's Cutie Mark color it equals, so the file follows
     * the color when the guide changes (see `untokenize`). Like Winterchilla's `CGUtils::tokenizeSvg`, with the color's hex kept
     * in the token (`<!--@ID:RRGGBB[,ALPHA]-->`) so the file can still be rendered when the color is later deleted.
     * Colors the guide does not have become `<!--#/RRGGBBAA-->`.
     *
     * @param  array<int, string>  $colors  color ID => hex of the Cutie Mark color group
     */
    public static function tokenize(string $svg, array $colors): string
    {
        $ids_by_hex = [];
        foreach ($colors as $id => $hex) {
            $parsed = RGBAColor::parse($hex);
            if ($parsed !== null) {
                $ids_by_hex[$parsed->toHex()] ??= $id;
            }
        }

        RGBAColor::forEachColorIn($svg, static function (?RGBAColor $color) use ($ids_by_hex) {
            if ($color === null) {
                return '';
            }
            $id = $ids_by_hex[$color->toHex()] ?? null;
            if ($id === null) {
                return sprintf('<!--#/%s-->', strtoupper(substr($color->toHexa(), 1)));
            }

            return sprintf('<!--@%d:%s%s-->', $id, strtoupper(substr($color->toHex(), 1)), $color->isTransparent() ? ','.$color->alpha : '');
        });

        return $svg;
    }

    /**
     * Turns the tokens of `tokenize` back into colors, using the guide's current colors (the color stored in the token when
     * the guide no longer has it).
     *
     * @param  array<int, string>  $colors  color ID => hex of the Cutie Mark color group
     * @param  string[]|null  $warnings  filled with colors that are not part of the guide
     */
    public static function untokenize(string $svg, array $colors, ?array &$warnings = null): string
    {
        $svg = preg_replace_callback('/<!--@(\d+):([\dA-F]{6})(?:,([\d.]+))?-->/', static function (array $match) use ($colors) {
            $color = RGBAColor::parse($colors[(int) $match[1]] ?? '') ?? RGBAColor::parse('#'.$match[2]);
            $color->alpha = (float) ($match[3] ?? 1);

            return (string) $color;
        }, $svg);

        $unknown = [];
        $svg = preg_replace_callback('~<!--#/([\dA-F]{8})-->~', static function (array $match) use (&$unknown) {
            $color = RGBAColor::parse('#'.$match[1]);
            $unknown[$match[1]] = "Unexpected color $color (not found in Cutie Mark color group)";

            return (string) $color;
        }, $svg);

        if ($warnings !== null) {
            $warnings = array_merge($warnings, array_values($unknown));
        }

        return $svg;
    }
}
