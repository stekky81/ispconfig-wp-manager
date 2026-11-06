<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;
use WpManager\HealthCheck;
use WpManager\PathGuard;
use WpManager\Runner;
use WpManager\SiteBackups;
use WpManager\SiteUpdater;

final class SiteUpdaterTest extends TestCase
{
    private string $root;
    /** @var list<string> */
    public array $events = [];
    public bool $backupFails = false;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wpm-upd-' . bin2hex(random_bytes(4));
        mkdir($base . '/client1/web7/web', 0o755, true);
        $this->root = (string)realpath($base);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function site(): array
    {
        return ['document_root' => $this->root . '/client1/web7', 'system_user' => 'web7', 'domain' => 'a.test', 'site_url' => 'http://a.test'];
    }

    private function fakeBackups(): SiteBackups
    {
        return new class ($this) implements SiteBackups {
            public function __construct(private readonly SiteUpdaterTest $test)
            {
            }
            public function create(array $site, ?Runner $runner = null): string
            {
                if ($this->test->backupFails) {
                    throw new \RuntimeException('disk full');
                }
                $this->test->events[] = 'backup';
                return '20260101-000000';
            }
            public function restore(array $site, string $id, ?Runner $runner = null): void
            {
                $this->test->events[] = "restore {$id}";
            }
        };
    }

    /** @param list<int> $homeCodes one answer per health check */
    private function updater(array $homeCodes): SiteUpdater
    {
        $i = 0;
        $fetch = static function (string $url) use (&$i, $homeCodes): array {
            if (str_ends_with($url, '/wp-login.php')) {
                return ['code' => 200, 'body' => ''];
            }
            return ['code' => $homeCodes[min($i++, count($homeCodes) - 1)], 'body' => ''];
        };
        return new SiteUpdater(Config::fromArray([]), new PathGuard($this->root), $this->fakeBackups(), new HealthCheck($fetch));
    }

    public function testHealthyUpdateKeepsTheBackup(): void
    {
        $runner = new Runner(true);
        $result = $this->updater([200, 200])->update($this->site(), 'all', $runner);

        self::assertSame('updated', $result['outcome']);
        self::assertSame(['backup'], $this->events);
        $log = implode("\n", $runner->log());
        self::assertStringContainsString("'core' 'update'", $log);
        self::assertStringContainsString("'core' 'update-db'", $log);
        self::assertStringContainsString("'plugin' 'update' '--all'", $log);
        self::assertStringContainsString("'theme' 'update' '--all'", $log);
    }

    public function testNothingRunsOnASiteAlreadyBroken(): void
    {
        $runner = new Runner(true);
        $result = $this->updater([500])->update($this->site(), 'all', $runner);

        self::assertSame('refused', $result['outcome']);
        self::assertSame([], $this->events);
        self::assertSame([], $runner->log());
    }

    public function testNoBackupNoUpdate(): void
    {
        $this->backupFails = true;
        $runner = new Runner(true);

        try {
            $this->updater([200])->update($this->site(), 'all', $runner);
            self::fail('expected the backup failure');
        } catch (\RuntimeException $e) {
            self::assertSame('disk full', $e->getMessage());
        }
        self::assertSame([], $runner->log());
    }

    public function testABrokenSiteIsRolledBack(): void
    {
        $result = $this->updater([200, 500, 200])->update($this->site(), 'plugins', new Runner(true));

        self::assertSame('rolled_back', $result['outcome']);
        self::assertSame(['backup', 'restore 20260101-000000'], $this->events);
        self::assertStringContainsString('home', $result['message']);
    }

    public function testARollbackThatDoesNotHelpIsReported(): void
    {
        $result = $this->updater([200, 500, 500])->update($this->site(), 'plugins', new Runner(true));

        self::assertSame('rollback_failed', $result['outcome']);
    }

    public function testAFailedCommandIsRolledBack(): void
    {
        // Not a dry run: the command really fails, because the WP-CLI path does not exist here.
        $updater = new SiteUpdater(
            Config::fromArray(['wp_cli' => '/nonexistent/wp']),
            new PathGuard($this->root),
            $this->fakeBackups(),
            new HealthCheck(static fn(string $url): array => ['code' => 200, 'body' => '']),
        );
        $result = $updater->update($this->site(), 'core', new Runner(false, [], 5));

        self::assertSame('rolled_back', $result['outcome']);
        self::assertSame(['backup', 'restore 20260101-000000'], $this->events);
    }

    /** @dataProvider scopes */
    public function testScopeLimitsTheCommands(string $scope, array $present, array $absent): void
    {
        $runner = new Runner(true);
        $this->updater([200, 200])->update($this->site(), $scope, $runner);
        $log = implode("\n", $runner->log());

        foreach ($present as $text) {
            self::assertStringContainsString($text, $log);
        }
        foreach ($absent as $text) {
            self::assertStringNotContainsString($text, $log);
        }
    }

    public static function scopes(): array
    {
        return [
            'core' => ['core', ["'core' 'update'"], ["'plugin'", "'theme'"]],
            'plugins' => ['plugins', ["'plugin' 'update'"], ["'core'", "'theme'"]],
            'themes' => ['themes', ["'theme' 'update'"], ["'core'", "'plugin'"]],
        ];
    }

    public function testAutomaticUpdatesKeepCoreOnMinorReleases(): void
    {
        $runner = new Runner(true);
        $this->updater([200, 200])->update($this->site(), SiteUpdater::AUTOMATIC, $runner);
        $log = implode("\n", $runner->log());

        self::assertStringContainsString("'core' 'update' '--minor'", $log);
        self::assertStringContainsString("'plugin' 'update' '--all'", $log);
        self::assertStringContainsString("'theme' 'update' '--all'", $log);
    }

    /** @dataProvider minorScopes */
    public function testOnlyTheMajorButtonLeavesTheBranch(string $scope, bool $minor): void
    {
        $runner = new Runner(true);
        $this->updater([200, 200])->update($this->site(), $scope, $runner);
        $log = implode("\n", $runner->log());

        self::assertStringContainsString("'core' 'update'", $log);
        self::assertSame($minor, str_contains($log, "'--minor'"));
    }

    public static function minorScopes(): array
    {
        return [
            'all' => ['all', true],
            'core' => ['core', true],
            'automatic' => [SiteUpdater::AUTOMATIC, true],
            'major' => ['major', false],
        ];
    }

    public function testAMajorUpdateRunsTheDatabaseUpgradeAndNothingElse(): void
    {
        $runner = new Runner(true);
        $this->updater([200, 200])->update($this->site(), 'major', $runner);
        $log = implode("\n", $runner->log());

        self::assertStringContainsString("'core' 'update-db'", $log);
        self::assertStringNotContainsString("'plugin'", $log);
    }

    public function testAMajorUpdateIsRolledBackToo(): void
    {
        $result = $this->updater([200, 500, 200])->update($this->site(), 'major', new Runner(true));

        self::assertSame('rolled_back', $result['outcome']);
    }

    public function testThePanelCanNotAskForTheAutomaticScope(): void
    {
        self::assertNotContains(SiteUpdater::AUTOMATIC, SiteUpdater::SCOPES);
    }

    public function testUnknownScopeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->updater([200])->update($this->site(), 'everything; rm', new Runner(true));
    }

    public function testCodeOfPluginsDoesNotRunDuringTheUpdate(): void
    {
        $runner = new Runner(true);
        $this->updater([200, 200])->update($this->site(), 'all', $runner);

        foreach ($runner->log() as $line) {
            self::assertStringContainsString("'--skip-plugins'", $line);
            self::assertStringContainsString("'-u' 'web7'", $line);
        }
    }

    // Must-use plugins load whatever the options, and automatic updates start with nobody looking.
    public function testUpdatesRunInTheSandbox(): void
    {
        $runner = new Runner(true);
        $this->updater([200, 200])->update($this->site(), SiteUpdater::AUTOMATIC, $runner);

        self::assertCount(4, $runner->log());
        foreach ($runner->log() as $line) {
            self::assertStringContainsString("open_basedir={$this->root}/client1/web7:", $line);
            self::assertStringContainsString('ffi.enable=0', $line);
        }
    }
}
