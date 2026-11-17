<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

/**
 * Maintenance mode of a site, with the `.maintenance` file of WordPress. WordPress ignores that file
 * after 10 minutes: this one sets the time at every request, so it lasts until it is removed. Browsers
 * with a WordPress login cookie, and the login page itself, still see the site. Faking the cookie only
 * shows a site under maintenance: it gives no right in WordPress, which checks the cookie itself.
 *
 * Updates of WordPress write and remove their own `.maintenance`: whatever changes the files of a
 * site keeps the state it found (SiteUpdater, SiteBackup, SiteSync).
 */
final class MaintenanceMode
{
    public const MARKER = 'ispconfig-wpstation maintenance mode';

    /** The cookie HealthCheck sends to see the site behind the maintenance page. */
    public const BYPASS_COOKIE = 'wordpress_logged_in_wpstation_health_check';

    private const FILE = '.maintenance';

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
    ) {
    }

    /** WordPress includes it inside wp_is_maintenance(), where $upgrading is global. */
    public static function fileContents(): string
    {
        return <<<'PHP'
            <?php
            // ispconfig-wpstation maintenance mode: turn it off in the panel, which removes this file.
            // Visitors get the maintenance page of WordPress (HTTP 503); the login page, and browsers with a
            // WordPress login cookie, see the site. WordPress itself checks that cookie.
            $upgrading = time();
            if (substr((string)($_SERVER['SCRIPT_NAME'] ?? ''), -13) === '/wp-login.php') {
                $upgrading = 0;
            }
            foreach (array_keys($_COOKIE) as $wpstation_cookie) {
                if (strpos((string)$wpstation_cookie, 'wordpress_logged_in_') === 0) {
                    $upgrading = 0;
                }
            }

            PHP;
    }

    /**
     * Only the file of this module: the one of a running WordPress update is not a maintenance mode.
     * A dry run reads nothing, and finds it off.
     */
    public function isOn(array $site, Runner $runner): bool
    {
        if ($runner->isDryRun()) {
            return false;
        }
        return $runner->run(new Command(['grep', '-qF', '--', self::MARKER, $this->path($site)], (string)$site['system_user']))['exit'] === 0;
    }

    public function set(array $site, bool $on, ?Runner $runner = null): void
    {
        $runner ??= new Runner($this->config->isDryRun());
        $user = (string)$site['system_user'];
        if ($on) {
            $runner->mustRun(new Command(['tee', '--', $this->path($site)], $user, null, self::fileContents()));
        } elseif ($runner->isDryRun() || $this->isOn($site, $runner)) {
            $runner->mustRun(new Command(['rm', '-f', '--', $this->path($site)], $user));
        }
    }

    private function path(array $site): string
    {
        return Subfolder::webRoot($site, $this->guard) . '/' . self::FILE;
    }
}
