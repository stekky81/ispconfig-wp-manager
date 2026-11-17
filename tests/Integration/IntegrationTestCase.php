<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WpManager\Command;
use WpManager\Config;
use WpManager\HealthCheck;
use WpManager\PathGuard;
use WpManager\Runner;

/**
 * Real users, folders, MariaDB and WordPress, prepared by dev/integration.sh, without ISPConfig. Run as root, with the
 * variables of /etc/wpm-it.env; skipped otherwise. Site 901 has WordPress, site 902 is the empty target of a clone.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if ((string)getenv('WPM_IT_BASE') === '' || posix_geteuid() !== 0) {
            self::markTestSkipped('No integration environment: run dev/integration.sh setup, then the tests as root with /etc/wpm-it.env');
        }
    }

    protected static function config(): Config
    {
        return Config::fromArray([
            'dry_run' => false,
            'web_root_base' => (string)getenv('WPM_IT_BASE'),
            'wp_cli' => (string)getenv('WPM_IT_WP_CLI'),
            'php_cli' => (string)getenv('WPM_IT_PHP'),
        ]);
    }

    protected static function guard(): PathGuard
    {
        return new PathGuard((string)getenv('WPM_IT_BASE'));
    }

    protected static function site(int $n): array
    {
        return [
            'wpm_site_id' => $n,
            'server_id' => 1,
            'status' => 'installed',
            'domain' => "it{$n}.test",
            'site_url' => "http://it{$n}.test",
            'document_root' => getenv('WPM_IT_BASE') . "/client901/web{$n}",
            'system_user' => "web{$n}",
            'db_name' => "wpmit_web{$n}",
            'db_user' => "wpmit_web{$n}",
            'db_host' => (string)getenv('WPM_IT_DB_HOST'),
        ];
    }

    protected static function web(int $n): string
    {
        return self::site($n)['document_root'] . '/web';
    }

    /** WP-CLI as the site user, without plugins and themes, for the checks of a test. */
    protected static function wp(int $n, string ...$args): string
    {
        $command = new Command(
            array_merge([(string)getenv('WPM_IT_WP_CLI')], $args, ['--path=' . self::web($n), '--skip-plugins', '--skip-themes']),
            "web{$n}",
            '/tmp',
        );
        return trim((new Runner(false, [], 120))->mustRun($command));
    }

    /** A command as the site user. */
    protected static function as(int $n, array $args, ?string $stdin = null): array
    {
        return (new Runner(false, [], 120))->run(new Command($args, "web{$n}", '/tmp', $stdin));
    }

    /** No web server here: the pages answer 200, the addresses a hardening measure must close 403. */
    protected static function health(): HealthCheck
    {
        $fetch = static fn(string $url): array => [
            'code' => preg_match('#/(xmlrpc\.php|wp-config\.php\.wpm-hardening-check|wp-content/uploads/wpm-hardening-check\.php)$#', $url) ? 403 : 200,
            'body' => '',
        ];
        return new HealthCheck($fetch, '127.0.0.1', 0);
    }
}
