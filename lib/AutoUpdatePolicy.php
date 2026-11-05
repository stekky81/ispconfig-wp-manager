<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Decides whether a site gets its automatic update: plugins, themes and minor core releases,
 * never a new major branch of WordPress.
 */
final class AutoUpdatePolicy
{
    /** 7.1.3 → 7.1.4 is minor; 7.1.4 → 7.2 is a new branch, as for WordPress itself. */
    public static function isMinorCoreUpdate(string $from, string $to): bool
    {
        $branch = static fn(string $version): string => implode('.', array_slice(explode('.', $version), 0, 2));
        return $from !== '' && $branch($from) === $branch($to) && version_compare($to, $from, '>');
    }

    public static function hasAutomaticUpdates(array $report): bool
    {
        return self::automaticUpdates($report) !== [];
    }

    /** Identifies a set of versions. A set that was rolled back is not tried again. */
    public static function fingerprint(array $report): string
    {
        return sha1(implode("\n", self::automaticUpdates($report)));
    }

    public static function shouldRun(array $site, array $report, bool $enabled): bool
    {
        return $enabled
            && ($site['auto_update'] ?? 'n') === 'y'
            && self::hasAutomaticUpdates($report)
            && (string)($site['auto_skip'] ?? '') !== self::fingerprint($report);
    }

    /** A new branch of WordPress on offer, only ever installed on request; '' if there is none. */
    public static function majorUpdate(array $report): string
    {
        $core = (string)($report['core_update'] ?? '');
        return $core !== '' && !self::isMinorCoreUpdate((string)($report['version'] ?? ''), $core) ? $core : '';
    }

    private static function minorUpdate(array $report): string
    {
        $version = (string)($report['version'] ?? '');
        foreach ([$report['core_minor'] ?? '', $report['core_update'] ?? ''] as $candidate) {
            if ($candidate !== null && self::isMinorCoreUpdate($version, (string)$candidate)) {
                return (string)$candidate;
            }
        }
        return '';
    }

    /** @return list<string> */
    private static function automaticUpdates(array $report): array
    {
        $updates = [];
        $core = self::minorUpdate($report);
        if ($core !== '') {
            $updates[] = "core {$core}";
        }
        foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $key => $type) {
            foreach ($report[$key] ?? [] as $item) {
                if ($item['update'] !== '') {
                    $updates[] = "{$type} {$item['name']} {$item['update']}";
                }
            }
        }
        sort($updates);
        return $updates;
    }
}
