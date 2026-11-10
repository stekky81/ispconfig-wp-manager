<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Rules of a staging site: one per production site, created as a clone with settings of its own.
 * See docs/architecture.md (Staging).
 */
final class SiteStaging
{
    /**
     * Settings of the ISPConfig website, not of WordPress, so that a push never carries them:
     * no mail from PHP, because a copy of a shop has real customers, and no search engines.
     */
    public const WEBSITE = [
        'custom_php_ini' => 'sendmail_path = /bin/true',
        'apache_directives' => 'Header set X-Robots-Tag "noindex, nofollow"',
    ];

    public static function defaultDomain(string $production): string
    {
        $production = strtolower(trim($production));
        return 'staging.' . (str_starts_with($production, 'www.') ? substr($production, 4) : $production);
    }

    /**
     * What a push would take back or remove on production, from the reports of the daily check:
     * newer versions, and plugins and themes that the staging does not have.
     *
     * @return list<string>
     */
    public static function lostOnPush(array $production, array $staging): array
    {
        $lines = [];
        $core = (string)($production['version'] ?? '');
        $stagingCore = (string)($staging['version'] ?? '');
        if ($core !== '' && $stagingCore !== '' && version_compare($core, $stagingCore, '>')) {
            $lines[] = "WordPress {$core} (staging {$stagingCore})";
        }
        foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $list => $kind) {
            $onStaging = array_column(is_array($staging[$list] ?? null) ? $staging[$list] : [], 'version', 'name');
            foreach (is_array($production[$list] ?? null) ? $production[$list] : [] as $item) {
                $name = (string)($item['name'] ?? '');
                $version = (string)($item['version'] ?? '');
                if ($name === '') {
                    continue;
                }
                if (!array_key_exists($name, $onStaging)) {
                    $lines[] = "{$kind} {$name} {$version} (not on the staging: it will be removed)";
                } elseif ($version !== '' && version_compare($version, (string)$onStaging[$name], '>')) {
                    $lines[] = "{$kind} {$name} {$version} (staging {$onStaging[$name]})";
                }
            }
        }
        return $lines;
    }

    /** @param array|null $staging the current staging of the site, if any */
    public static function assertCanCreate(array $production, ?array $staging): void
    {
        if (($production['status'] ?? '') !== 'installed') {
            throw new \RuntimeException("{$production['domain']} is not installed");
        }
        if ((int)($production['staging_of'] ?? 0) > 0) {
            throw new \RuntimeException("{$production['domain']} is a staging site itself");
        }
        if ($staging !== null) {
            throw new \RuntimeException("{$production['domain']} already has a staging site: {$staging['domain']}");
        }
    }
}
