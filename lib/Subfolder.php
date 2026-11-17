<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * A WordPress site in a folder of an existing website (example.com/blog), decided with the maintainer on 2026-11-17:
 * one level below web/, sharing the website, its users and its PHP version with whatever is in web/ itself.
 *
 * A site with an empty subfolder lives in web/. Everything that copies or replaces web/ (backups, restores, clones,
 * stagings) leaves out the folders below it that hold a WordPress of their own: otherwise the restore of the site
 * in web/ would delete or roll back the site in web/blog.
 */
final class Subfolder
{
    /** Folders that ISPConfig, Apache or WordPress use already. */
    public const RESERVED = [
        'cgi-bin', 'error', 'stats', 'webalizer', 'awstats', 'wp-admin', 'wp-content', 'wp-includes',
    ];

    /** A folder name typed in the panel: lowercase, digits, hyphens. */
    public static function forNewSite(string $name): string
    {
        $name = trim($name, " \t/");
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name)) {
            throw new ValidationException('The folder must be one name of lowercase letters, digits and hyphens, without /');
        }
        return self::name($name);
    }

    /**
     * A folder name from a record or from the disk: also the names of existing sites, which may have capitals,
     * dots and underscores. Never a path, never hidden, never reserved.
     */
    public static function name(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $name) || str_contains($name, '..')) {
            throw new ValidationException("Invalid folder: {$name}");
        }
        if (in_array(strtolower($name), self::RESERVED, true)) {
            throw new ValidationException("The folder {$name} is used by ISPConfig or WordPress");
        }
        return $name;
    }

    public static function isValid(string $name): bool
    {
        try {
            self::name($name);
            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /** The subfolder of a record, '' for a site in web/. */
    public static function of(array $site): string
    {
        $name = (string)($site['subfolder'] ?? '');
        return $name === '' ? '' : self::name($name);
    }

    /** The folder of WordPress: <document_root>/web or <document_root>/web/<subfolder>. */
    public static function webRoot(array $site, PathGuard $guard): string
    {
        $web = $guard->assertInside(rtrim((string)$site['document_root'], '/') . '/web');
        $name = self::of($site);
        if ($name === '') {
            return $web;
        }
        // The site owner controls web/: a link could send the worker to any folder, of another site too.
        $folder = $guard->assertInside("{$web}/{$name}");
        if (is_link("{$web}/{$name}") || $folder !== "{$web}/{$name}") {
            throw new \RuntimeException("{$web}/{$name} is a link: refused");
        }
        return $folder;
    }

    /** How the panel and the emails name a site: example.com or example.com/blog. */
    public static function label(array $site): string
    {
        $name = (string)($site['subfolder'] ?? '');
        return (string)($site['domain'] ?? '') . ($name === '' ? '' : '/' . $name);
    }

    /** The address of a record: its site_url, or http:// with the domain and the folder for records without one. */
    public static function siteUrl(array $site): string
    {
        $url = (string)($site['site_url'] ?? '');
        return rtrim($url !== '' ? $url : self::url('http://' . $site['domain'], (string)($site['subfolder'] ?? '')), '/');
    }

    /**
     * http or https for a website of ISPConfig: https only when it has a certificate, from Let's Encrypt or its own. ISPConfig
     * keeps `ssl` on when Let's Encrypt fails and only turns `ssl_letsencrypt` off, and then nothing answers on 443.
     */
    public static function scheme(array $website): string
    {
        $ssl = ($website['ssl'] ?? 'n') === 'y';
        return $ssl && (($website['ssl_letsencrypt'] ?? 'n') === 'y' || trim((string)($website['ssl_cert'] ?? '')) !== '') ? 'https' : 'http';
    }

    /** The address of a new site in a folder, from the address of the website. */
    public static function url(string $websiteUrl, string $name): string
    {
        return rtrim($websiteUrl, '/') . ($name === '' ? '' : '/' . $name);
    }

    /**
     * Folders right below a web root that hold a WordPress of their own: a real wp-config.php in a real folder,
     * as the scan of the nodes finds them. Only for a site in web/; a site in a subfolder has none.
     *
     * @return list<string> sorted names
     */
    public static function nested(array $site, PathGuard $guard): array
    {
        if (self::of($site) !== '') {
            return [];
        }
        $web = self::webRoot($site, $guard);
        $found = [];
        foreach (glob($web . '/*/wp-config.php', GLOB_NOSORT) ?: [] as $config) {
            $folder = dirname($config);
            $name = basename($folder);
            if (self::isValid($name) && !is_link($folder) && !is_link($config) && is_file($config)) {
                $found[] = $name;
            }
        }
        sort($found);
        return $found;
    }

    /**
     * Options that leave those folders out: 'tar' for an archive made with -C <web root> ., 'rsync' for a copy
     * into a web root, where an excluded path is also safe from --delete.
     *
     * @param list<string> $names
     * @return list<string>
     */
    public static function excludes(array $names, string $tool): array
    {
        return array_map(
            static fn(string $name): string => '--exclude=' . ($tool === 'tar' ? './' : '/') . self::name($name),
            array_values(array_unique($names)),
        );
    }
}
