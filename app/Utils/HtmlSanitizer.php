<?php

namespace App\Utils;

use HTMLPurifier;
use HTMLPurifier_Config;
use HTMLPurifier_TagTransform_Simple;
use RuntimeException;

/**
 * Same rules as Winterchilla's `CoreUtils::sanitizeHtml`: bold and italic are always allowed (normalized to strong/em),
 * everything else must be listed, and disallowed markup is escaped instead of silently dropped.
 */
class HtmlSanitizer
{
    public static function sanitize(string $dirty_html, array $allowed_tags = [], ?array $allowed_attributes = null): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.AllowedElements', array_merge(['strong', 'b', 'em', 'i'], $allowed_tags));
        $config->set('HTML.AllowedAttributes', $allowed_attributes ?? []);
        $config->set('Core.EscapeInvalidTags', true);

        $definition = $config->getHTMLDefinition(true);
        if ($definition === null) {
            throw new RuntimeException(__METHOD__.': the HTML definition should never be null');
        }
        $definition->info_tag_transform['b'] = new HTMLPurifier_TagTransform_Simple('strong');
        $definition->info_tag_transform['i'] = new HTMLPurifier_TagTransform_Simple('em');

        return trim((new HTMLPurifier($config))->purify($dirty_html));
    }
}
