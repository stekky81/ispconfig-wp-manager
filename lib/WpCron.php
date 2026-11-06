<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Switches a site between WP-Cron, run by page visits, and a real cron job that ISPConfig
 * manages. The job calls the site over HTTP, so it runs on the PHP version of the site.
 */
final class WpCron
{
    public const WORDPRESS = 'wp';
    public const REAL = 'real';
    private const CONSTANT = 'DISABLE_WP_CRON';

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $guard,
    ) {
    }

    /** Changes wp-config.php, as the site user. */
    public function apply(array $site, string $mode, ?Runner $runner = null): void
    {
        if (!in_array($mode, [self::WORDPRESS, self::REAL], true)) {
            throw new \InvalidArgumentException("Invalid cron mode: {$mode}");
        }
        $runner ??= new Runner($this->config->isDryRun());
        $this->guard->assertInside(rtrim((string)$site['document_root'], '/') . '/web');
        $siteRoot = $this->guard->assertInside((string)$site['document_root']);
        // These commands do not load WordPress, but a wp-cli.yml of the site can still require PHP.
        $wp = WpCli::sandboxed($this->config->get('wp_cli'), $this->config->get('php_cli'), $siteRoot, (string)$site['system_user']);

        if ($mode === self::REAL) {
            $runner->mustRun($wp->configSet(self::CONSTANT, 'true', true));
        } elseif ($runner->isDryRun() || $runner->run($wp->configHas(self::CONSTANT))['exit'] === 0) {
            $runner->mustRun($wp->configDelete(self::CONSTANT));
        }
    }

    /** @return array<string, mixed> the job for the Remote API */
    public static function jobParams(array $site): array
    {
        return [
            'server_id' => (int)$site['server_id'],
            'parent_domain_id' => (int)$site['domain_id'],
            'type' => 'url',
            'command' => HealthCheck::baseUrl($site) . '/wp-cron.php?doing_wp_cron',
            'run_min' => '*/5',
            'run_hour' => '*',
            'run_mday' => '*',
            'run_month' => '*',
            'run_wday' => '*',
            'log' => 'n',
            'active' => 'y',
        ];
    }

    /** @param array{exit:int, stdout:string, stderr:string} $result of `wp config get DISABLE_WP_CRON` */
    public static function isDisabled(array $result): bool
    {
        $value = strtolower(trim($result['stdout']));
        return $result['exit'] === 0 && !in_array($value, ['', '0', 'false'], true);
    }
}
