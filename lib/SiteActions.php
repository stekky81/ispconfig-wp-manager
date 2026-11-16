<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * The actions on an existing site that the panel asks for and the worker of its node runs, once.
 * The panel writes `action_request` and `action_param`; the datalog brings them to the node.
 */
final class SiteActions
{
    public const ALL = [
        'backup', 'restore', 'update', 'wpcron', 'refresh', 'push',
        'maintenance', 'debug', 'debuglog', 'login', 'password', 'harden', 'fix_permissions',
        ...SiteExtensions::ACTIONS,
    ];

    /** The actions of the site page, with a parameter checked against allowedParams(). */
    public const SITE_PAGE = [
        'backup', 'restore', 'update', 'wpcron',
        'maintenance', 'debug', 'debuglog', 'login', 'password', 'harden', 'fix_permissions',
        ...SiteExtensions::ACTIONS,
    ];

    /** Fits `action_param`. */
    public const MAX_PARAM = 255;

    /**
     * What the panel accepts as the parameter of an action, from the record of the site: only what the
     * last check of the node saw. The worker checks the site again before it acts.
     *
     * @return list<string>
     */
    public static function allowedParams(string $action, array $site): array
    {
        $report = json_decode((string)($site['updates_json'] ?? ''), true);
        $report = is_array($report) ? $report : [];
        $items = static function (string $key, array $statuses) use ($report): array {
            $names = [];
            foreach (is_array($report[$key] ?? null) ? $report[$key] : [] as $item) {
                $name = is_array($item) ? (string)($item['name'] ?? '') : '';
                if ($name !== '' && in_array((string)($item['status'] ?? ''), $statuses, true) && self::isExtensionName($name)) {
                    $names[] = $name;
                }
            }
            return $names;
        };
        return match ($action) {
            'restore' => array_values(array_filter(
                array_map('strval', array_column(json_decode((string)($site['backups_json'] ?? ''), true) ?: [], 'id')),
            )),
            'update' => SiteUpdater::SCOPES,
            'wpcron' => [WpCron::WORDPRESS, WpCron::REAL],
            'maintenance', 'debug' => ['on', 'off'],
            'password' => array_values(array_map(
                static fn(array $admin): string => (string)(int)$admin['id'],
                array_filter(is_array($report['admins'] ?? null) ? $report['admins'] : [], static fn($a): bool => is_array($a) && (int)($a['id'] ?? 0) > 0),
            )),
            'plugin_activate' => $items('plugins', ['inactive']),
            'plugin_deactivate' => $items('plugins', ['active']),
            'plugin_delete' => $items('plugins', ['active', 'inactive']),
            'theme_activate' => $items('themes', ['inactive', 'parent']),
            'theme_delete' => $items('themes', ['inactive']),
            'harden' => array_merge(...array_map(static fn(string $m): array => ["{$m}:on", "{$m}:off"], SiteHardening::MEASURES)),
            'backup', 'debuglog', 'login', 'fix_permissions' => [''],
            default => [],
        };
    }

    /** The login of an administrator, by the ID the panel sends for a password reset; '' if the last check did not see it. */
    public static function adminLogin(array $site, string $id): string
    {
        $report = json_decode((string)($site['updates_json'] ?? ''), true);
        foreach (is_array($report['admins'] ?? null) ? $report['admins'] : [] as $admin) {
            if (is_array($admin) && (string)(int)($admin['id'] ?? 0) === $id) {
                return (string)($admin['login'] ?? '');
            }
        }
        return '';
    }

    private static function isExtensionName(string $name): bool
    {
        try {
            Validator::wpExtension($name);
            return strlen($name) <= self::MAX_PARAM;
        } catch (ValidationException) {
            return false;
        }
    }
}
