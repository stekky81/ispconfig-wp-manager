<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WPStation\Command;
use WPStation\Config;
use WPStation\HealthCheck;
use WPStation\PathGuard;
use WPStation\Runner;

/**
 * Real users, folders, MariaDB and WordPress, prepared by dev/integration.sh, without ISPConfig. Run as root, with the
 * variables of /etc/wpstation-it.env; skipped otherwise. Site 901 has WordPress, site 902 is the empty target of a clone.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if ((string)getenv('WPSTATION_IT_BASE') === '' || posix_geteuid() !== 0) {
            self::markTestSkipped('No integration environment: run dev/integration.sh setup, then the tests as root with /etc/wpstation-it.env');
        }
    }

    protected static function config(): Config
    {
        return Config::fromArray([
            'dry_run' => false,
            'web_root_base' => (string)getenv('WPSTATION_IT_BASE'),
            'wp_cli' => (string)getenv('WPSTATION_IT_WP_CLI'),
            'php_cli' => (string)getenv('WPSTATION_IT_PHP'),
        ]);
    }

    protected static function guard(): PathGuard
    {
        return new PathGuard((string)getenv('WPSTATION_IT_BASE'));
    }

    protected static function site(int $n): array
    {
        return [
            'wpstation_site_id' => $n,
            'server_id' => 1,
            'status' => 'installed',
            'domain' => "it{$n}.test",
            'site_url' => "http://it{$n}.test",
            'document_root' => getenv('WPSTATION_IT_BASE') . "/client901/web{$n}",
            'system_user' => "web{$n}",
            'db_name' => "wpsit_web{$n}",
            'db_user' => "wpsit_web{$n}",
            'db_host' => (string)getenv('WPSTATION_IT_DB_HOST'),
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
            array_merge([(string)getenv('WPSTATION_IT_WP_CLI')], $args, ['--path=' . self::web($n), '--skip-plugins', '--skip-themes']),
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

    /** No web server here: the pages answer 200, the addresses a hardening measure must close 403 (404 for the users). */
    protected static function health(): HealthCheck
    {
        $fetch = static fn(string $url): array => [
            'code' => match (true) {
                (bool)preg_match('#/(xmlrpc\.php|wp-config\.php\.wpstation-hardening-check|wp-content/uploads/wpstation-hardening-check\.php)$#', $url) => 403,
                str_ends_with($url, '/?rest_route=/wp/v2/users') => 404,
                default => 200,
            },
            'body' => '',
        ];
        return new HealthCheck($fetch, '127.0.0.1', 0);
    }
}
