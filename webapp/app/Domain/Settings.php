<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * app_settings, which is where the limits live so that the agents, the screens
 * and the database all read the same numbers instead of hardcoding 100 in five
 * places.
 *
 * @mock mock-api/server.mjs:325-340 (setting / configPayload)
 */
final class Settings
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) return self::$cache[$key];
        $raw = Db::one('SELECT setting_value FROM app_settings WHERE setting_key = ?', [$key]);
        return self::$cache[$key] = $raw === null ? $default : Canon::decode((string) $raw, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function str(string $key, string $default = ''): string
    {
        return (string) self::get($key, $default);
    }

    public static function set(string $key, mixed $value, string $by = 'owner'): void
    {
        Db::exec(
            'INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
            [$key, Canon::encode($value), $by]
        );
        unset(self::$cache[$key]);
    }

    /** Every numeric value is cast, so it serialises as a JSON number. */
    public static function configPayload(): array
    {
        return [
            'timezone'               => self::str('timezone', 'Asia/Kolkata'),
            'max_body_words'         => self::int('max_body_words', 100),
            'topics_per_batch_min'   => self::int('topics_per_batch_min', 30),
            'topics_per_batch_max'   => self::int('topics_per_batch_max', 60),
            'approved_queue_ceiling' => self::int('approved_queue_ceiling', 21),
            'images_per_post'        => self::int('images_per_post', 2),
            'metrics_window_days'    => self::int('metrics_window_days', 183),
            'package_ttl_days'       => self::int('package_ttl_days', 45),
            'company_website'        => self::str('company_website'),
            'company_email'          => self::str('company_email'),
            'linkedin_org_urn'       => self::str('linkedin_org_urn'),
        ];
    }

    public static function all(): array
    {
        return Db::all('SELECT setting_key, setting_value, updated_at, updated_by FROM app_settings ORDER BY setting_key');
    }
}
