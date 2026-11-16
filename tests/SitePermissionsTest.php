<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;
use WpManager\HealthCheck;
use WpManager\PathGuard;
use WpManager\Runner;
use WpManager\SitePermissions;

final class SitePermissionsTest extends TestCase
{
    public function testTheOutputOfFindIsCounted(): void
    {
        $web = '/var/www/clients/client1/web7/web';
        $output = "W\t{$web}/wp-content/cache\nO\troot\t{$web}/stats\nW\t{$web}/x.php\ngarbage\n";
        $report = SitePermissions::parse($output, $web, '644');

        self::assertSame(2, $report['world_writable']);
        self::assertSame(1, $report['foreign']);
        self::assertSame(['writable by anyone: /wp-content/cache', 'owned by root: /stats', 'writable by anyone: /x.php'], $report['examples']);
        self::assertSame('644', $report['wp_config']);
        self::assertSame('', SitePermissions::parse('', $web, 'stat: cannot')['wp_config']);
    }

    public function testWhatNeedsAFix(): void
    {
        self::assertTrue(SitePermissions::configTooOpen('644'));
        self::assertTrue(SitePermissions::configTooOpen('666'));
        self::assertFalse(SitePermissions::configTooOpen('640'));
        self::assertFalse(SitePermissions::configTooOpen('600'));
        self::assertFalse(SitePermissions::needsFix(['world_writable' => 0, 'foreign' => 3, 'wp_config' => '640']), 'other owners are only reported');
        self::assertTrue(SitePermissions::needsFix(['world_writable' => 1, 'wp_config' => '640']));
    }

    public function testTheFixRunsAsTheSiteUserOnItsOwnFiles(): void
    {
        $base = sys_get_temp_dir() . '/wpm-perm-' . bin2hex(random_bytes(4));
        mkdir($base . '/client1/web7/web', 0o755, true);
        $root = (string)realpath($base);
        try {
            $runner = new Runner(true);
            $permissions = new SitePermissions(Config::fromArray([]), new PathGuard($root), new HealthCheck(static fn(): array => ['code' => 200, 'body' => ''], '127.0.0.1', 0));
            $result = $permissions->fix(['document_root' => $root . '/client1/web7', 'system_user' => 'web7', 'domain' => 'a.test'], $runner);
            $log = implode("\n", $runner->log());

            self::assertSame('done', $result['outcome']);
            self::assertStringContainsString("'-u' 'web7' '-H' '--' 'find' '{$root}/client1/web7/web' '-xdev' '!' '-type' 'l' '-perm' '-0002' '-user' 'web7' '-print0'", $log);
            self::assertStringContainsString("'chmod' '640' '--' '{$root}/client1/web7/web/wp-config.php'", $log);
            self::assertStringNotContainsString("'chown'", $log);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }
}
