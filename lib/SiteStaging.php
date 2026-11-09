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
