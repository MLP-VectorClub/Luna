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
    // Same keys and defaults as Winterchilla's GlobalSettings
    public const DEFAULT_SETTINGS = [
        'reservation_rules' => '',
        'about_reservations' => '',
        'dev_role_label' => 'developer',
    ];

    public static function get(string $setting): string
    {
        return settings($setting, self::DEFAULT_SETTINGS[$setting] ?? null);
    }

    /**
     * Stores the value, a value equal to the default removes the row (like Winterchilla does)
     */
    public static function set(string $setting, string $value): void
    {
        if ($value === (self::DEFAULT_SETTINGS[$setting] ?? null)) {
            settings()->remove($setting);
            return;
        }

        settings()->set($setting, $value);
    }
}
