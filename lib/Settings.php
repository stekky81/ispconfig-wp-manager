<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

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
        'notifications' => ['recipients', 'summary', 'site_ready'],
        'hardening' => ['new_sites'],
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
            'hardening' => [
                // Checkboxes: absent when none is ticked.
                'new_sites' => array_values(array_intersect(
                    SiteHardening::MEASURES,
                    is_array($in['hardening_new_sites'] ?? null) ? $in['hardening_new_sites'] : [],
                )),
            ],
            'notifications' => [
                'recipients' => implode(', ', Notifications::parseRecipients((string)($in['notify_recipients'] ?? ''))),
                'summary' => self::choice($in['notify_summary'] ?? null, Notifications::FREQUENCIES),
                'site_ready' => self::choice($in['notify_site_ready'] ?? null, ['y', 'n']),
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

    /**
     * What changed between two sets of settings, as "section.key: old → new", for the audit log.
     *
     * @return list<string>
     */
    public static function changes(array $before, array $after): array
    {
        $show = static fn(mixed $value): string => $value === null ? '-' : (is_array($value) ? implode(', ', $value) : (string)$value);
        $changes = [];
        foreach (self::ALLOWED as $section => $keys) {
            foreach ($keys as $key) {
                $old = $before[$section][$key] ?? null;
                $new = $after[$section][$key] ?? null;
                if ($old !== $new) {
                    $changes[] = "{$section}.{$key}: {$show($old)} → {$show($new)}";
                }
            }
        }
        return $changes;
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
