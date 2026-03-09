<?php
/**
 * ImpulseMinio Settings Helper
 *
 * Provides read access to the mod_impulseminio_settings table for
 * the server module. This file can be included from anywhere without
 * requiring the addon module to be loaded.
 *
 * @package ImpulseMinio
 * @version 1.0.0
 */
namespace WHMCS\Module\Server\ImpulseMinio;

use WHMCS\Database\Capsule;

class Settings
{
    /** @var array<string, string|null> In-memory cache */
    private static array $cache = [];

    /** @var string[] Keys that are stored encrypted */
    private static array $encryptedKeys = ['cf_api_token'];

    /**
     * Get a setting value by key.
     *
     * @param  string      $key
     * @param  string|null $default
     * @return string|null
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key] ?? $default;
        }

        try {
            $row = Capsule::table('mod_impulseminio_settings')
                ->where('setting_key', $key)
                ->first();

            if (!$row) {
                self::$cache[$key] = null;
                return $default;
            }

            $value = $row->setting_value;

            // Decrypt sensitive fields
            if (in_array($key, self::$encryptedKeys) && !empty($value)) {
                $value = decrypt($value);
            }

            self::$cache[$key] = $value;
            return $value;
        } catch (\Exception $e) {
            return $default;
        }
    }

    /**
     * Get a setting as an integer.
     *
     * @param  string $key
     * @param  int    $default
     * @return int
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $val = self::get($key);
        return $val !== null ? (int)$val : $default;
    }

    /**
     * Get a setting as a boolean.
     *
     * @param  string $key
     * @param  bool   $default
     * @return bool
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $val = self::get($key);
        if ($val === null) return $default;
        return in_array(strtolower($val), ['1', 'true', 'yes', 'on']);
    }

    /**
     * Set a setting value.
     *
     * @param  string $key
     * @param  string $value
     * @return void
     */
    public static function set(string $key, string $value): void
    {
        $storeValue = $value;
        if (in_array($key, self::$encryptedKeys) && !empty($value)) {
            $storeValue = encrypt($value);
        }

        Capsule::table('mod_impulseminio_settings')->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => $storeValue, 'updated_at' => date('Y-m-d H:i:s')]
        );

        // Update cache
        self::$cache[$key] = $value;
    }

    /**
     * Check if the settings table exists.
     *
     * @return bool
     */
    public static function isReady(): bool
    {
        try {
            return Capsule::schema()->hasTable('mod_impulseminio_settings');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clear the in-memory cache.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
