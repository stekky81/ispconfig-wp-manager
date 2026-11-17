<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

/**
 * Finds the WordPress installs of this node, so that sites not created by this module
 * appear in the list too. It only reads the folder tree: it changes nothing in the sites.
 */
final class SiteScanner
{
    public function __construct(
        private readonly PathGuard $guard,
        private readonly string $root,
    ) {
    }

    /** @return list<string> document roots with a real wp-config.php in web/, sorted */
    public function scan(): array
    {
        $found = [];
        foreach (glob($this->root . '/client*/web*/web/wp-config.php') ?: [] as $config) {
            $web = dirname($config);
            // Site owners control these folders: a link could point anywhere.
            if (is_link($config) || is_link($web) || !is_file($config)) {
                continue;
            }
            $found[] = $this->guard->assertInside(dirname($web));
        }
        sort($found);
        return array_values(array_unique($found));
    }

    /**
     * WordPress installs one level below web/ (example.com/blog), found the same way. Folders with names the module
     * does not accept, and links, are left out.
     *
     * @return list<array{0:string, 1:string}> document root and folder, sorted
     */
    public function scanSubfolders(): array
    {
        $found = [];
        foreach (glob($this->root . '/client*/web*/web/*/wp-config.php') ?: [] as $config) {
            $folder = dirname($config);
            $web = dirname($folder);
            $name = basename($folder);
            if (!Subfolder::isValid($name) || is_link($config) || is_link($folder) || is_link($web) || !is_file($config)) {
                continue;
            }
            $found[] = [$this->guard->assertInside(dirname($web)), $name];
        }
        sort($found);
        return array_values(array_unique($found, SORT_REGULAR));
    }

    /**
     * @param array $domain the web_domain record of the site
     * @return array<string, mixed> the wpstation_site record: no secrets, this module did not create them
     */
    public static function recordFor(array $domain, int $clientId, string $wpVersion, string $subfolder = ''): array
    {
        $extra = $subfolder === '' ? [] : [
            'subfolder' => Subfolder::name($subfolder),
            // Without it the checks would ask the address of the website, which is another site.
            'site_url' => Subfolder::url(Subfolder::scheme($domain) . '://' . $domain['domain'], $subfolder),
        ];
        return $extra + [
            'sys_userid' => (int)$domain['sys_userid'],
            'sys_groupid' => (int)$domain['sys_groupid'],
            'sys_perm_user' => (string)$domain['sys_perm_user'],
            'sys_perm_group' => (string)$domain['sys_perm_group'],
            'sys_perm_other' => (string)$domain['sys_perm_other'],
            'server_id' => (int)$domain['server_id'],
            'client_id' => $clientId,
            'domain_id' => (int)$domain['domain_id'],
            'domain' => (string)$domain['domain'],
            'system_user' => (string)$domain['system_user'],
            'document_root' => (string)$domain['document_root'],
            'origin' => 'detected',
            'status' => 'installed',
            'status_message' => 'Existing WordPress install found on the node.',
            'wp_version' => $wpVersion,
        ];
    }
}
