<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Caches in the database that hold pages or parts of pages already built with the old address,
 * sometimes encoded where no replace reaches (Elementor keeps base64 HTML). After a clone or a
 * sync they are deleted; WordPress and the plugins build them again on the first visit.
 * Add a plugin here when a test finds another one.
 */
final class SiteCaches
{
    /** Transients are temporary by definition; on a single site the site transients live here too. */
    private const OPTION_PATTERNS = ['\\_transient\\_%', '\\_site\\_transient\\_%'];
    private const OPTIONS = [
        '_elementor_global_css',
    ];
    private const POST_META = [
        '_elementor_element_cache',
        '_elementor_css',
    ];

    public static function clearSql(string $prefix): string
    {
        $prefix = SiteCloner::tablePrefix($prefix);
        $list = static fn(array $names): string => implode(', ', array_map(static fn(string $name): string => "'{$name}'", $names));
        $patterns = implode(' OR ', array_map(static fn(string $pattern): string => "option_name LIKE '{$pattern}'", self::OPTION_PATTERNS));
        return 'DELETE FROM ' . SiteDatabase::quoteName($prefix . 'options')
            . " WHERE {$patterns} OR option_name IN (" . $list(self::OPTIONS) . ");\n"
            . 'DELETE FROM ' . SiteDatabase::quoteName($prefix . 'postmeta')
            . ' WHERE meta_key IN (' . $list(self::POST_META) . ");\n";
    }
}
