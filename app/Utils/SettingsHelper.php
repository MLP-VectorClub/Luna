<?php


namespace App\Utils;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="AppSettings",
 *     type="string",
 *     description="List of supported application-wide settings",
 *     enum=APP_SETTINGS,
 * )
 */
class SettingsHelper
{
    public const DEFAULT_SETTINGS = [
        'dev_role_label' => 'staff',
    ];

    public static function get(string $setting): string
    {
        return settings($setting, self::DEFAULT_SETTINGS[$setting] ?? null);
    }
}
