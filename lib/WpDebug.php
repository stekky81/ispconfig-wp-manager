<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * The debug mode of WordPress, with the log outside the web root: `wp-content/debug.log`, the default,
 * can be downloaded by anyone. Errors are never shown in the pages. The worker turns it off after
 * HOURS, so that it is not forgotten on.
 */
final class WpDebug
{
    public const HOURS = 24;
    /** In the `private` folder that ISPConfig creates next to `web`, which PHP of the site may write. */
    public const LOG = 'private/wp-debug.log';
    public const TAIL_LINES = 200;
    public const MAX_TAIL = 65536;

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
    ) {
    }

    /** Changes wp-config.php, as the site user. */
    public function set(array $site, bool $on, ?Runner $runner = null): void
    {
        $runner ??= new Runner($this->config->isDryRun());
        $wp = $this->wp($site);
        if ($on) {
            // Display off and the log in place before WP_DEBUG: with WP_DEBUG alone, visitors see the errors.
            $runner->mustRun($wp->configSet('WP_DEBUG_DISPLAY', 'false', true));
            $runner->mustRun($wp->configSet('WP_DEBUG_LOG', $this->logPath($site)));
            $runner->mustRun($wp->configSet('WP_DEBUG', 'true', true));
            return;
        }
        $runner->mustRun($wp->configSet('WP_DEBUG', 'false', true));
        foreach (['WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY'] as $name) {
            if ($runner->isDryRun() || $runner->run($wp->configHas($name))['exit'] === 0) {
                $runner->mustRun($wp->configDelete($name));
            }
        }
    }

    /** The last lines of the log, read as the site user: '' when there is none yet. */
    public function tail(array $site, ?Runner $runner = null): string
    {
        $runner ??= new Runner($this->config->isDryRun(), [], 60);
        $result = $runner->run(new Command(
            ['tail', '-n', (string)self::TAIL_LINES, '--', $this->logPath($site)],
            (string)$site['system_user'],
        ));
        return $result['exit'] === 0 ? self::limited($result['stdout']) : '';
    }

    /** The end of the text, from the start of a line, and valid UTF-8: it is shown in the panel. */
    public static function limited(string $text): string
    {
        if (strlen($text) > self::MAX_TAIL) {
            $text = substr($text, -self::MAX_TAIL);
            $newline = strpos($text, "\n");
            $text = $newline === false ? $text : substr($text, $newline + 1);
        }
        return mb_scrub($text, 'UTF-8');
    }

    public function logPath(array $site): string
    {
        $siteRoot = $this->guard->assertInside((string)$site['document_root']);
        $private = $siteRoot . '/private';
        if (!is_dir($private) || is_link($private)) {
            throw new \RuntimeException("{$private} does not exist: the debug log stays outside the web root, there");
        }
        return $siteRoot . '/' . self::log($site);
    }

    /** The log, from the folder of the website: a site in a subfolder has its own. */
    public static function log(array $site): string
    {
        $name = Subfolder::of($site);
        return $name === '' ? self::LOG : 'private/wp-debug-' . $name . '.log';
    }

    private function wp(array $site): WpCli
    {
        // These commands do not load WordPress, but a wp-cli.yml of the site can still require PHP.
        return WpCli::forSite($this->config, $this->guard, $site);
    }
}
