<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

/**
 * A must-use plugin of this module in a site: `wp-content/mu-plugins/<name>`, written and removed as the site user.
 * Only a file that carries its marker is ever changed or removed; a site that keeps its must-use plugins elsewhere
 * (WPMU_PLUGIN_DIR, WP_CONTENT_DIR) is left alone, since WordPress would not load the file.
 */
final class MustUsePlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
    ) {
    }

    public static function path(string $name): string
    {
        if (!preg_match('/^wpstation-[a-z-]+\.php$/', $name)) {
            throw new \InvalidArgumentException("Not a must-use plugin of the module: {$name}");
        }
        return 'wp-content/mu-plugins/' . $name;
    }

    /** Whether the site has the file with its marker. A dry run reads nothing, and finds nothing. */
    public function has(array $site, string $name, string $marker, Runner $runner): bool
    {
        if ($runner->isDryRun()) {
            return false;
        }
        $current = $runner->run(new Command(['cat', '--', $this->file($site, $name)], (string)$site['system_user']));
        return $current['exit'] === 0 && str_contains($current['stdout'], $marker);
    }

    /**
     * Puts $contents in the file, or removes it when $contents is null. Returns '' when the file is as asked, or why it
     * was left as it is.
     */
    public function put(array $site, string $name, string $marker, ?string $contents, Runner $runner): string
    {
        if ($contents !== null && !str_contains($contents, $marker)) {
            throw new \InvalidArgumentException('The contents must carry the marker');
        }
        if ($runner->isDryRun()) {
            return '';
        }
        $user = (string)$site['system_user'];
        $root = Subfolder::webRoot($site, $this->guard);
        $file = $this->file($site, $name);

        $current = $runner->run(new Command(['cat', '--', $file], $user));
        $ours = $current['exit'] === 0 && str_contains($current['stdout'], $marker);
        if ($current['exit'] === 0 && !$ours) {
            return 'A file ' . self::path($name) . ' that WPStation did not write is in the way: left alone.';
        }
        if ($contents === null) {
            if ($ours) {
                $runner->mustRun(new Command(['rm', '-f', '--', $file], $user));
            }
            return '';
        }
        $wp = WpCli::forSite($this->config, $this->guard, $site);
        foreach (['WPMU_PLUGIN_DIR', 'WP_CONTENT_DIR'] as $constant) {
            if ($runner->run($wp->configHas($constant))['exit'] === 0) {
                if ($ours) {
                    $runner->mustRun(new Command(['rm', '-f', '--', $file], $user));
                }
                return "wp-config.php sets {$constant}: WordPress would not load a file in wp-content/mu-plugins.";
            }
        }
        if ($runner->run(new Command(['test', '-d', $root . '/wp-content'], $user))['exit'] !== 0) {
            return 'The site has no wp-content folder.';
        }
        if ($current['exit'] === 0 && $current['stdout'] === $contents) {
            return '';
        }
        $runner->mustRun(new Command(['mkdir', '-p', '--', dirname($file)], $user));
        $runner->mustRun(new Command(['tee', '--', $file], $user, null, $contents));
        return '';
    }

    private function file(array $site, string $name): string
    {
        return Subfolder::webRoot($site, $this->guard) . '/' . self::path($name);
    }
}
