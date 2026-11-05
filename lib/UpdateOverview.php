<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Reads the update reports stored in wpm_site for the panel. Plain text: escape it for HTML.
 */
final class UpdateOverview
{
    /**
     * @param list<array> $rows wpm_site rows with server_name
     * @return list<array{server_name:string, sites:int, with_updates:int, core:int, plugins:int, themes:int}>
     */
    public static function byServer(array $rows): array
    {
        $servers = [];
        foreach ($rows as $row) {
            $name = (string)$row['server_name'];
            $servers[$name] ??= ['server_name' => $name, 'sites' => 0, 'with_updates' => 0, 'core' => 0, 'plugins' => 0, 'themes' => 0];
            $servers[$name]['sites']++;
            if ((int)$row['updates_count'] > 0) {
                $servers[$name]['with_updates']++;
            }
            foreach (self::available($row['updates_json'] ?? null) as $item) {
                $servers[$name][$item['type'] === 'core' ? 'core' : $item['type'] . 's']++;
            }
        }
        return array_values($servers);
    }

    /** @return list<array{type:string, name:string, version:string, update:string}> */
    public static function available(?string $json): array
    {
        $report = self::decode($json);
        $items = [];
        if (!empty($report['core_update'])) {
            $items[] = ['type' => 'core', 'name' => 'WordPress', 'version' => (string)($report['version'] ?? ''), 'update' => (string)$report['core_update']];
        }
        foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $key => $type) {
            foreach ((array)($report[$key] ?? []) as $item) {
                if (is_array($item) && (string)($item['update'] ?? '') !== '') {
                    $items[] = ['type' => $type, 'name' => (string)($item['name'] ?? ''), 'version' => (string)($item['version'] ?? ''), 'update' => (string)$item['update']];
                }
            }
        }
        return $items;
    }

    /** Why the last check failed, or ''. */
    public static function error(?string $json): string
    {
        return (string)(self::decode($json)['error'] ?? '');
    }

    private static function decode(?string $json): array
    {
        $data = json_decode((string)$json, true);
        return is_array($data) ? $data : [];
    }
}
