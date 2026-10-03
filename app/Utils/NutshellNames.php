<?php

namespace App\Utils;

use App\Models\Appearance;

/**
 * The alternative names of the 2020 "nutshell names" mode (resources/data/nutshell_names.json, a copy of Winterchilla's config/nutshell_names.php).
 * The API only hands them out, the front end shows one of them in place of the label when the visitor's `cg_nutshell` preference is on
 */
class NutshellNames
{
    /** @var array<int|string, string[]>|null */
    private static ?array $names = null;

    /**
     * @return string[] the names the appearance can be shown as, empty when it has none and for personal guide appearances (never renamed)
     */
    public static function for(Appearance $appearance): array
    {
        if ($appearance->owner_id !== null) {
            return [];
        }
        self::$names ??= json_decode(file_get_contents(resource_path('data/nutshell_names.json')), true, 512, JSON_THROW_ON_ERROR);

        return self::$names[$appearance->id] ?? [];
    }
}
