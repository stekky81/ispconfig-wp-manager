<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;
use WpManager\PathGuard;
use WpManager\Runner;
use WpManager\SiteInventory;

final class SiteInventoryTest extends TestCase
{
    private const PLUGINS = '[{"name":"akismet","status":"active","version":"5.0","update":"available","update_version":"5.7.2"},'
        . '{"name":"hello","status":"inactive","version":"1.7.2","update":"none","update_version":""}]';
    private const THEMES = '[{"name":"astra","status":"active","version":"4.0","update":"available","update_version":"4.1"}]';
    private const CORE = '[{"version":"7.2","update_type":"minor","package_url":"x"},{"version":"8.0","update_type":"major","package_url":"y"}]';

    public function testReportListsVersionsAndUpdates(): void
    {
        $report = SiteInventory::report("7.1.4\n", self::CORE, self::PLUGINS, self::THEMES);

        self::assertSame('7.1.4', $report['version']);
        self::assertSame('8.0', $report['core_update']);
        self::assertSame(
            ['name' => 'akismet', 'status' => 'active', 'version' => '5.0', 'update' => '5.7.2'],
            $report['plugins'][0],
        );
        self::assertSame('', $report['plugins'][1]['update']);
        self::assertSame('4.1', $report['themes'][0]['update']);
    }

    public function testMinorReleaseIsKeptNextToANewBranch(): void
    {
        $core = '[{"version":"7.2","update_type":"major"},{"version":"7.1.5","update_type":"minor"}]';
        $report = SiteInventory::report('7.1.4', $core, '[]', '[]');

        self::assertSame('7.2', $report['core_update']);
        self::assertSame('7.1.5', $report['core_minor']);
    }

    public function testDryRunSaysNothingAboutCron(): void
    {
        self::assertFalse(SiteInventory::report('7.1.4', '[]', '[]', '[]')['wp_cron_disabled']);
    }

    public function testUpdateCountAddsCorePluginsAndThemes(): void
    {
        self::assertSame(3, SiteInventory::updateCount(SiteInventory::report('7.1.4', self::CORE, self::PLUGINS, self::THEMES)));
        self::assertSame(0, SiteInventory::updateCount(SiteInventory::report('7.1.4', '[]', '[]', '[]')));
    }

    public function testAnUpdateWithoutAVersionStillCounts(): void
    {
        $report = SiteInventory::report('7.1.4', '[]', '[{"name":"x","status":"active","version":"1","update":"available"}]', '[]');

        self::assertSame('available', $report['plugins'][0]['update']);
        self::assertSame(1, SiteInventory::updateCount($report));
    }

    // Premium plugins can report "unavailable": there is nothing to install from wordpress.org.
    public function testAnUnavailableUpdateDoesNotCount(): void
    {
        $report = SiteInventory::report('7.1.4', '[]', '[{"name":"x","status":"active","version":"1","update":"unavailable"}]', '[]');

        self::assertSame(0, SiteInventory::updateCount($report));
    }

    public function testNoticesBeforeTheJsonAreIgnored(): void
    {
        $report = SiteInventory::report('7.1.4', '[]', "PHP Notice: something\n" . self::PLUGINS . "\n", '[]');

        self::assertCount(2, $report['plugins']);
    }

    public function testOutputThatIsNotJsonIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        SiteInventory::report('7.1.4', '[]', 'Fatal error: broken site', '[]');
    }

    // The output comes from a process of the site user: the site owner can control it.
    public function testHostileOutputIsLimited(): void
    {
        $items = array_fill(0, 2000, ['name' => str_repeat('a', 5000), 'status' => ['x'], 'version' => 1, 'update' => 'none']);
        $report = SiteInventory::report('7.1.4', '[]', (string)json_encode($items), '[1,"x"]');

        self::assertCount(500, $report['plugins']);
        self::assertSame(100, strlen($report['plugins'][0]['name']));
        self::assertSame('', $report['plugins'][0]['status']);
        self::assertSame([], $report['themes']);
    }

    public function testDryRunRunsNothingAndReportsNothing(): void
    {
        $root = sys_get_temp_dir() . '/wpm-inv-' . bin2hex(random_bytes(4));
        mkdir($root . '/client1/web1/web', 0o755, true);
        $inventory = new SiteInventory(Config::fromArray([]), new PathGuard($root));
        $runner = new Runner(true);

        $report = $inventory->collect(['document_root' => $root . '/client1/web1', 'system_user' => 'web1'], $runner);

        exec('rm -rf ' . escapeshellarg($root));
        self::assertSame(0, SiteInventory::updateCount($report));
        self::assertStringContainsString("'--skip-plugins'", implode("\n", $runner->log()));
        self::assertStringContainsString("'-u' 'web1'", implode("\n", $runner->log()));
    }
}
