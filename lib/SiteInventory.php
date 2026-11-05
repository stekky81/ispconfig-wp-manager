<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Reads the versions and available updates of a site. Runs on the web node.
 * It changes no file: WordPress only refreshes its own update transients.
 * The output comes from a process of the site user, so it is treated as untrusted.
 */
final class SiteInventory
{
    private const MAX_ITEMS = 500;
    private const MAX_LENGTH = 100;

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
    ) {
    }

    /** @return array{version:string, core_update:?string, core_minor:?string, plugins:list<array>, themes:list<array>} */
    public function collect(array $site, ?Runner $runner = null): array
    {
        $runner ??= new Runner($this->config->isDryRun(), [], 120);
        $webRoot = $this->guard->assertInside(rtrim((string)$site['document_root'], '/') . '/web');
        $wp = new WpCli($this->config->get('wp_cli'), $webRoot, (string)$site['system_user']);

        return self::report(
            $runner->mustRun($wp->coreVersion()),
            self::orEmptyList($runner->mustRun($wp->coreCheckUpdate())),
            self::orEmptyList($runner->mustRun($wp->pluginList())),
            self::orEmptyList($runner->mustRun($wp->themeList())),
        );
    }

    public static function report(string $version, string $core, string $plugins, string $themes): array
    {
        $installed = WordPressInstaller::parseVersion($version);
        $coreUpdate = null;
        $coreMinor = null;
        foreach (self::decode($core) as $item) {
            $candidate = is_array($item) ? self::text($item['version'] ?? '') : '';
            if ($candidate !== '' && ($coreUpdate === null || version_compare($candidate, $coreUpdate, '>'))) {
                $coreUpdate = $candidate;
            }
            if (AutoUpdatePolicy::isMinorCoreUpdate($installed, $candidate) && ($coreMinor === null || version_compare($candidate, $coreMinor, '>'))) {
                $coreMinor = $candidate;
            }
        }
        return [
            'version' => $installed,
            'core_update' => $coreUpdate,
            'core_minor' => $coreMinor,
            'plugins' => self::items(self::decode($plugins)),
            'themes' => self::items(self::decode($themes)),
        ];
    }

    public static function updateCount(array $report): int
    {
        $withUpdate = static fn(array $item): bool => $item['update'] !== '';
        return (empty($report['core_update']) ? 0 : 1)
            + count(array_filter($report['plugins'], $withUpdate))
            + count(array_filter($report['themes'], $withUpdate));
    }

    /** In dry-run nothing runs, so there is no output to read. */
    private static function orEmptyList(string $output): string
    {
        return trim($output) === '' ? '[]' : $output;
    }

    /** WP-CLI prints JSON on one line, after any PHP notice of the site. */
    private static function decode(string $output): array
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $data = json_decode((string)end($lines), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Unexpected WP-CLI output');
        }
        return $data;
    }

    private static function items(array $data): array
    {
        $items = [];
        foreach (array_slice($data, 0, self::MAX_ITEMS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $available = ($item['update'] ?? '') === 'available';
            $update = self::text($item['update_version'] ?? '');
            $items[] = [
                'name' => self::text($item['name'] ?? ''),
                'status' => self::text($item['status'] ?? ''),
                'version' => self::text($item['version'] ?? ''),
                'update' => $available ? ($update !== '' ? $update : 'available') : '',
            ];
        }
        return $items;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? substr((string)$value, 0, self::MAX_LENGTH) : '';
    }
}
