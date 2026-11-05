<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Global defaults edited in the panel and stored on the master. They override config.php for
 * new sites only. dry_run, the Remote API account and the key file stay in config.php:
 * nothing here can change them, not even a tampered database row.
 */
final class Settings
{
    private const ALLOWED = [
        'site_defaults' => ['server_php_id', 'hd_quota', 'traffic_quota', 'ssl', 'ssl_letsencrypt', 'subdomain'],
        'wordpress' => ['locale', 'plugins', 'theme'],
        'updates' => ['auto'],
    ];

    /** @return array<string, array<string, mixed>> validated overrides for Config::withOverrides() */
    public static function fromInput(array $in): array
    {
        $theme = (string)($in['theme'] ?? '');
        return [
            'site_defaults' => [
                'server_php_id' => Validator::phpVersionId($in['server_php_id'] ?? null),
                'hd_quota' => self::quota($in['hd_quota'] ?? null),
                'traffic_quota' => self::quota($in['traffic_quota'] ?? null),
                'ssl' => self::choice($in['ssl'] ?? null, ['y', 'n']),
                'ssl_letsencrypt' => self::choice($in['ssl_letsencrypt'] ?? null, ['y', 'n']),
                'subdomain' => self::choice($in['subdomain'] ?? null, ['none', 'www', '*']),
            ],
            'wordpress' => [
                'locale' => Validator::locale((string)($in['locale'] ?? '')),
                'plugins' => Validator::wpSlugList((string)($in['plugins'] ?? '')),
                'theme' => $theme === '' ? '' : Validator::wpSlug($theme),
            ],
            'updates' => [
                'auto' => self::choice($in['auto_updates'] ?? null, ['y', 'n']),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> the allowed part of the stored settings */
    public static function fromJson(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach (self::ALLOWED as $section => $keys) {
            foreach ($keys as $key) {
                if (is_array($data[$section] ?? null) && array_key_exists($key, $data[$section])) {
                    $out[$section][$key] = $data[$section][$key];
                }
            }
        }
        return $out;
    }

    /** Size in MB, -1 = unlimited. */
    private static function quota(mixed $value): int
    {
        $quota = filter_var($value, FILTER_VALIDATE_INT);
        if ($quota === false || $quota < -1) {
            throw new ValidationException('Invalid quota: ' . var_export($value, true));
        }
        return $quota;
    }

    /** @param list<string> $allowed */
    private static function choice(mixed $value, array $allowed): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new ValidationException('Invalid value: ' . var_export($value, true));
        }
        return $value;
    }
}
