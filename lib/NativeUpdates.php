<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

/**
 * Who updates a site on its own: WordPress, or this module.
 *
 * - `wordpress`: the module updates nothing on its own; WordPress does what it is configured to do (minor core releases
 *   and translations by default, plugins and themes only if turned on in wp-admin).
 * - `plugins`: plugins and themes only through the module, with backup, health check and rollback; WordPress keeps
 *   minor core releases and translations, the most urgent and the least risky.
 * - `all`: core releases too go through the module; WordPress keeps the translations.
 *
 * The automatic updates of WordPress are turned off with a must-use plugin, written and removed as the site user.
 * It changes nothing else, and only a file with our marker is ever changed or removed.
 */
final class NativeUpdates
{
    public const MODES = ['wordpress', 'plugins', 'all'];
    public const MARKER = 'ispconfig-wpstation native updates';
    public const FILE = 'wp-content/mu-plugins/wpstation-updates.php';

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
    ) {
    }

    /** The mode of a record, from its two switches. */
    public static function mode(array $site): string
    {
        if (($site['auto_update'] ?? 'n') !== 'y') {
            return 'wordpress';
        }
        return ($site['auto_core'] ?? 'n') === 'y' ? 'all' : 'plugins';
    }

    /** @return array{auto_update:string, auto_core:string} the switches of a mode, for the record */
    public static function switches(string $mode): array
    {
        return match ($mode) {
            'wordpress' => ['auto_update' => 'n', 'auto_core' => 'n'],
            'plugins' => ['auto_update' => 'y', 'auto_core' => 'n'],
            'all' => ['auto_update' => 'y', 'auto_core' => 'y'],
            default => throw new \InvalidArgumentException("Unknown update mode: {$mode}"),
        };
    }

    /**
     * What the site must have: '' (nothing of ours), 'plugins' or 'all'. With the automatic updates of the module off in
     * the settings, WordPress gets them all back: otherwise nobody would update the site.
     */
    public static function desired(array $site, bool $enabled): string
    {
        $mode = self::mode($site);
        return $enabled && $mode !== 'wordpress' ? $mode : '';
    }

    public static function fileContents(string $state): string
    {
        if (!in_array($state, ['plugins', 'all'], true)) {
            throw new \InvalidArgumentException("Unknown state: {$state}");
        }
        $core = $state === 'all'
            ? "add_filter('auto_update_core', '__return_false', PHP_INT_MAX);\n"
            : '';
        $what = $state === 'all' ? 'WordPress, plugins and themes' : 'plugins and themes';
        return <<<PHP
            <?php
            /**
             * Plugin Name: WPStation updates
             * Description: Automatic updates of {$what} go through WPStation, with a backup, a health check and a rollback. Change it in the ISPConfig panel, not here.
             */
            // ispconfig-wpstation native updates: {$state}. WPStation writes and removes this file.
            add_filter('auto_update_plugin', '__return_false', PHP_INT_MAX);
            add_filter('auto_update_theme', '__return_false', PHP_INT_MAX);
            add_filter('plugins_auto_update_enabled', '__return_false', PHP_INT_MAX);
            add_filter('themes_auto_update_enabled', '__return_false', PHP_INT_MAX);
            {$core}
            PHP;
    }

    /**
     * What WordPress does on its own with core releases, from wp-config.php: 'off', 'minor' or 'all'. Plugins of the
     * site can change it too; this is what the configuration says.
     *
     * @param array{exit:int, stdout:string} $disabled `wp config get AUTOMATIC_UPDATER_DISABLED`
     * @param array{exit:int, stdout:string} $core     `wp config get WP_AUTO_UPDATE_CORE`
     */
    public static function coreState(array $disabled, array $core): string
    {
        if (WpCli::isTrue($disabled)) {
            return 'off';
        }
        if ($core['exit'] !== 0) {
            return 'minor';
        }
        $value = strtolower(trim($core['stdout']));
        return match ($value) {
            '', '0', 'false' => 'off',
            'minor' => 'minor',
            default => 'all',
        };
    }

    /** @return list<string> names with the automatic updates of WordPress turned on in wp-admin */
    public static function turnedOn(array $items): array
    {
        $names = [];
        foreach ($items as $item) {
            if (is_array($item) && ($item['auto_update'] ?? '') === 'on' && is_string($item['name'] ?? null)) {
                $names[] = substr($item['name'], 0, 100);
            }
        }
        return $names;
    }

    /**
     * What happens on the site on its own, for its page: language keys with an argument. $report is the last check of
     * the node (SiteInventory), which may be older than the mode.
     *
     * @return list<array{0:string, 1:string}>
     */
    public static function notes(string $mode, bool $enabled, array $report): array
    {
        $notes = [];
        $effective = $enabled ? $mode : 'wordpress';
        if (!$enabled && $mode !== 'wordpress') {
            $notes[] = ['native_global_off_txt', ''];
        }
        $core = is_string($report['native_core'] ?? null) ? $report['native_core'] : '';
        if ($effective === 'all') {
            $notes[] = ['native_core_ours_txt', ''];
        } elseif (in_array($core, ['off', 'minor', 'all'], true)) {
            $notes[] = ['native_core_' . $core . '_txt', ''];
            if ($effective === 'plugins' && $core === 'off') {
                $notes[] = ['native_core_nobody_txt', ''];
            }
        }
        if ($effective !== 'wordpress') {
            $notes[] = ['native_extensions_ours_txt', self::FILE];
        } elseif (isset($report['native_plugins']) || isset($report['native_themes'])) {
            $names = array_merge(
                is_array($report['native_plugins'] ?? null) ? $report['native_plugins'] : [],
                is_array($report['native_themes'] ?? null) ? $report['native_themes'] : [],
            );
            $notes[] = $names === []
                ? ['native_extensions_none_txt', '']
                : ['native_extensions_on_txt', implode(', ', array_map('strval', $names))];
        }
        return $notes;
    }

    /**
     * Brings the must-use plugin in line with $state. Returns '' when done, or why it was left as it is: the site then
     * keeps the automatic updates of WordPress, as before.
     */
    public function apply(array $site, string $state, ?Runner $runner = null): string
    {
        if (!in_array($state, ['', 'plugins', 'all'], true)) {
            throw new \InvalidArgumentException("Unknown state: {$state}");
        }
        $runner ??= new Runner($this->config->isDryRun(), [], 120);
        if ($runner->isDryRun()) {
            return '';
        }
        $user = (string)$site['system_user'];
        $root = Subfolder::webRoot($site, $this->guard);
        $file = $root . '/' . self::FILE;

        $current = $runner->run(new Command(['cat', '--', $file], $user));
        $ours = $current['exit'] === 0 && str_contains($current['stdout'], self::MARKER);
        if ($current['exit'] === 0 && !$ours) {
            return 'A file ' . self::FILE . ' that WPStation did not write is in the way: left alone.';
        }
        if ($state === '') {
            if ($ours) {
                $runner->mustRun(new Command(['rm', '-f', '--', $file], $user));
            }
            return '';
        }
        // WordPress would not load the file from the usual folder.
        $wp = WpCli::forSite($this->config, $this->guard, $site);
        foreach (['WPMU_PLUGIN_DIR', 'WP_CONTENT_DIR'] as $constant) {
            if ($runner->run($wp->configHas($constant))['exit'] === 0) {
                if ($ours) {
                    $runner->mustRun(new Command(['rm', '-f', '--', $file], $user));
                }
                return "wp-config.php sets {$constant}: WPStation cannot turn off the automatic updates of WordPress here.";
            }
        }
        if ($runner->run(new Command(['test', '-d', $root . '/wp-content'], $user))['exit'] !== 0) {
            return 'The site has no wp-content folder: the automatic updates of WordPress were left as they are.';
        }
        $contents = self::fileContents($state);
        if ($current['exit'] === 0 && $current['stdout'] === $contents) {
            return '';
        }
        $runner->mustRun(new Command(['mkdir', '-p', '--', dirname($file)], $user));
        $runner->mustRun(new Command(['tee', '--', $file], $user, null, $contents));
        return '';
    }
}
