<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Activates, deactivates and removes plugins and themes, with a way back like the updates: the site is
 * checked before and after, and what it does worse is undone. Removing takes a backup first, and removes
 * only the files: what a plugin keeps in the database stays, as with `wp plugin delete`.
 */
final class SiteExtensions
{
    public const ACTIONS = ['plugin_activate', 'plugin_deactivate', 'plugin_delete', 'theme_activate', 'theme_delete'];

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
        private readonly SiteBackups $backups,
        private readonly HealthCheck $health,
    ) {
    }

    /**
     * Why an action cannot run, from the state of the site; '' when it can.
     *
     * @param string $status of the item in `wp plugin list` or `wp theme list`: active, inactive, parent, must-use...
     */
    public static function refusal(string $action, string $status): string
    {
        $allowed = match ($action) {
            'plugin_activate' => ['inactive'],
            'plugin_deactivate' => ['active'],
            'plugin_delete' => ['active', 'inactive'],
            // A parent theme can be used on its own.
            'theme_activate' => ['inactive', 'parent'],
            // Neither the active theme nor its parent.
            'theme_delete' => ['inactive'],
            default => [],
        };
        if ($allowed === []) {
            return "unknown action {$action}";
        }
        if ($status === '') {
            return 'it is not installed';
        }
        return in_array($status, $allowed, true) ? '' : "it is {$status}";
    }

    /** @return array{outcome:string, message:string} outcome: done, refused, rolled_back or rollback_failed */
    public function run(array $site, string $action, string $slug, ?Runner $runner = null): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Invalid action: {$action}");
        }
        Validator::wpExtension($slug);
        $runner ??= new Runner($this->config->isDryRun(), [], 1800);
        $wp = $this->wp($site);
        $type = str_starts_with($action, 'plugin_') ? 'plugin' : 'theme';
        $name = "{$type} {$slug}";

        // The state now, not the one of the last check the panel saw.
        $reason = self::refusal($action, $runner->isDryRun() ? self::assumed($action) : $this->status($runner, $wp, $type, $slug));
        if ($reason !== '') {
            return ['outcome' => 'refused', 'message' => "Not done: the {$name} {$reason}. Nothing changed."];
        }

        $before = $this->health->run($site);
        $previousTheme = $type === 'theme' ? trim($runner->mustRun($wp->optionGet('stylesheet'))) : '';
        $backup = str_ends_with($action, '_delete') ? $this->backups->create($site, $runner) : '';
        $note = '';

        try {
            switch ($action) {
                case 'plugin_activate':
                    $runner->mustRun($wp->pluginActivate($slug));
                    break;
                case 'plugin_deactivate':
                case 'plugin_delete':
                    if ($action === 'plugin_deactivate' || $this->status($runner, $wp, 'plugin', $slug) === 'active') {
                        $note = $this->deactivate($runner, $wp, $slug);
                    }
                    if ($action === 'plugin_delete') {
                        $lines = preg_split('/\R/', trim($runner->mustRun($wp->pluginPath($slug)))) ?: [];
                        $target = $this->pluginFiles($site, $slug, $runner->isDryRun() ? '' : (string)end($lines));
                        $runner->mustRun(new Command(['rm', '-rf', '--', $target], (string)$site['system_user']));
                    }
                    break;
                case 'theme_activate':
                    $runner->mustRun($wp->themeActivate($slug));
                    break;
                case 'theme_delete':
                    $runner->mustRun($wp->themeDelete($slug));
                    break;
            }
        } catch (\RuntimeException $e) {
            return $this->undo($site, $action, $slug, $previousTheme, $backup, $before, $runner, ucfirst($this->verb($action)) . " of the {$name} failed: " . $e->getMessage());
        }

        $broken = $this->health->regressionsAfterChange($site, $before);
        if ($broken !== []) {
            return $this->undo($site, $action, $slug, $previousTheme, $backup, $before, $runner, "Broken after the {$this->verb($action)} of the {$name}: " . $this->health->describe($broken));
        }
        $kept = $backup === '' ? '' : " Backup {$backup} kept.";
        return ['outcome' => 'done', 'message' => ucfirst($name) . ' ' . $this->past($action) . ".{$note}{$kept}"];
    }

    /** Like wp-admin first; without the other plugins when WordPress itself breaks. */
    private function deactivate(Runner $runner, WpCli $wp, string $slug): string
    {
        if ($runner->run($wp->pluginDeactivate($slug))['exit'] === 0) {
            return '';
        }
        $runner->mustRun($wp->pluginDeactivate($slug, true));
        return ' WordPress did not load with the plugin, so its own deactivation code did not run.';
    }

    private function undo(array $site, string $action, string $slug, string $previousTheme, string $backup, array $before, Runner $runner, string $reason): array
    {
        $wp = $this->wp($site);
        try {
            if ($backup !== '') {
                $this->backups->restore($site, $backup, $runner);
                $undone = "Backup {$backup} restored";
            } elseif ($action === 'plugin_activate') {
                $runner->mustRun($wp->pluginDeactivate($slug, true));
                $undone = 'Plugin deactivated again';
            } elseif ($action === 'plugin_deactivate') {
                $runner->mustRun($wp->pluginActivate($slug));
                $undone = 'Plugin activated again';
            } elseif ($previousTheme !== '') {
                $runner->mustRun($wp->themeActivate($previousTheme));
                $undone = "Theme {$previousTheme} activated again";
            } else {
                return ['outcome' => 'rollback_failed', 'message' => "{$reason}. The previous theme is not known: check the site."];
            }
        } catch (\RuntimeException $e) {
            return ['outcome' => 'rollback_failed', 'message' => "{$reason}. Undoing it failed: " . $e->getMessage()];
        }
        $still = $this->health->regressionsAfterChange($site, $before);
        if ($still !== []) {
            return ['outcome' => 'rollback_failed', 'message' => "{$reason}. {$undone}, but still broken: " . $this->health->describe($still)];
        }
        return ['outcome' => 'rolled_back', 'message' => "{$reason}. {$undone}."];
    }

    /**
     * What to delete for a plugin, from the path of its main file: its folder, or the file alone for a plugin of one
     * file (the folder of that one is the folder of every plugin). Only inside the web root of the site.
     */
    public function pluginFiles(array $site, string $slug, string $mainFile): string
    {
        $web = Subfolder::webRoot($site, $this->guard);
        if ($mainFile === '') {
            // A dry run reads nothing: the usual place.
            return "{$web}/wp-content/plugins/{$slug}";
        }
        $real = realpath($mainFile);
        if ($real === false || !str_starts_with($real, $web . '/') || !str_ends_with($real, '.php')) {
            throw new \RuntimeException("The plugin {$slug} is not in the web root of the site: {$mainFile}");
        }
        $folder = dirname($mainFile);
        if (basename($folder) === $slug && realpath(dirname($folder)) !== false && str_starts_with((string)realpath(dirname($folder)), $web . '/')) {
            return $folder;
        }
        if (basename($mainFile) === $slug . '.php') {
            return $mainFile;
        }
        throw new \RuntimeException("Unexpected place for the plugin {$slug}: {$mainFile}");
    }

    /** '' when not installed. */
    private function status(Runner $runner, WpCli $wp, string $type, string $slug): string
    {
        if ($runner->run($wp->isInstalled($type, $slug))['exit'] !== 0) {
            return '';
        }
        $list = SiteInventory::decodeList($runner->mustRun($type === 'plugin' ? $wp->pluginList() : $wp->themeList()));
        foreach ($list as $item) {
            if (is_array($item) && ($item['name'] ?? null) === $slug) {
                return is_string($item['status'] ?? null) ? $item['status'] : '';
            }
        }
        return '';
    }

    /** In dry-run nothing is read: the state the action needs. */
    private static function assumed(string $action): string
    {
        return match ($action) {
            'plugin_activate', 'theme_activate', 'theme_delete' => 'inactive',
            default => 'active',
        };
    }

    private function verb(string $action): string
    {
        return ['plugin_activate' => 'activation', 'plugin_deactivate' => 'deactivation', 'plugin_delete' => 'removal',
            'theme_activate' => 'activation', 'theme_delete' => 'removal'][$action];
    }

    private function past(string $action): string
    {
        return ['plugin_activate' => 'activated', 'plugin_deactivate' => 'deactivated', 'plugin_delete' => 'removed',
            'theme_activate' => 'activated', 'theme_delete' => 'removed'][$action];
    }

    private function wp(array $site): WpCli
    {
        return WpCli::forSite($this->config, $this->guard, $site);
    }
}
