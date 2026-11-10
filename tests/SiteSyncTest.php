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
use WpManager\SiteSync;

final class SiteSyncTest extends TestCase
{
    private string $root;
    /** @var list<string> */
    public array $events = [];

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wpm-sync-' . bin2hex(random_bytes(4));
        foreach (['web7', 'web8'] as $site) {
            mkdir("{$base}/client1/{$site}/web", 0o755, true);
            mkdir("{$base}/client1/{$site}/tmp", 0o755, true);
        }
        $this->root = (string)realpath($base);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function production(): array
    {
        return [
            'wpm_site_id' => 7, 'staging_of' => 0, 'status' => 'installed', 'server_id' => 1, 'domain' => 'shop.test',
            'site_url' => 'http://shop.test', 'document_root' => $this->root . '/client1/web7', 'system_user' => 'web7',
        ];
    }

    private function staging(): array
    {
        return [
            'wpm_site_id' => 8, 'staging_of' => 7, 'status' => 'installed', 'server_id' => 1, 'domain' => 'staging.shop.test',
            'site_url' => 'http://staging.shop.test', 'document_root' => $this->root . '/client1/web8', 'system_user' => 'web8',
        ];
    }

    /** @param list<int> $homeCodes one answer per health check */
    private function sync(array $homeCodes = [200], array $config = []): SiteSync
    {
        $i = 0;
        $fetch = static function (string $url) use (&$i, $homeCodes): array {
            if (str_ends_with($url, '/wp-login.php')) {
                return ['code' => 200, 'body' => ''];
            }
            return ['code' => $homeCodes[min($i++, count($homeCodes) - 1)], 'body' => ''];
        };
        $backups = new class ($this) implements SiteBackups {
            public function __construct(private readonly SiteSyncTest $test)
            {
            }
            public function create(array $site, ?Runner $runner = null): string
            {
                $this->test->events[] = "backup {$site['system_user']}";
                return '20260101-000000';
            }
            public function restore(array $site, string $id, ?Runner $runner = null): void
            {
                $this->test->events[] = "restore {$site['system_user']} {$id}";
            }
        };
        return new SiteSync(Config::fromArray($config), new PathGuard($this->root), $backups, new HealthCheck($fetch, '127.0.0.1', 0));
    }

    private static function lines(Runner $runner, string $needle): array
    {
        return array_values(array_filter($runner->log(), static fn(string $line): bool => str_contains($line, $needle)));
    }

    public function testRefreshCopiesProductionOverTheStagingAfterABackupOfTheStaging(): void
    {
        $runner = new Runner(true);
        $result = $this->sync()->refresh($this->staging(), $this->production(), $runner);

        self::assertSame('done', $result['outcome']);
        self::assertSame(['backup web8'], $this->events);
        $log = implode("\n", $runner->log());
        self::assertStringContainsString("'-u' 'web7' '-H' '--' 'tar' '-cz'", $log);
        self::assertStringContainsString("'-C' '{$this->root}/client1/web7/web' '.' | ", $log);
        self::assertStringContainsString("'-u' 'web8' '-H' '--' 'rsync' '-a' '--delete'", $log);
        self::assertStringContainsString("'{$this->root}/client1/web8/web/'", $log);
        self::assertStringContainsString("'blog_public' '0'", $log);
    }

    public function testPushCopiesTheStagingOverProductionInMaintenanceMode(): void
    {
        $runner = new Runner(true);
        $result = $this->sync([200, 200])->push($this->staging(), $this->production(), $runner);

        self::assertSame('done', $result['outcome']);
        self::assertSame(['backup web7'], $this->events);
        $log = $runner->log();
        $file = "{$this->root}/client1/web7/web/.maintenance";
        $on = array_keys(array_filter($log, static fn(string $l): bool => str_contains($l, "'tee' '--' '{$file}'")));
        $copy = array_keys(array_filter($log, static fn(string $l): bool => str_contains($l, "'rsync'")));
        $off = array_keys(array_filter($log, static fn(string $l): bool => str_contains($l, "'rm' '-f' '--' '{$file}'")));
        self::assertCount(1, $on);
        self::assertCount(1, $copy);
        self::assertCount(1, $off);
        self::assertTrue($on[0] < $copy[0] && $copy[0] < $off[0], 'maintenance on, copy, maintenance off');
        self::assertStringContainsString("'-u' 'web8' '-H' '--' 'tar' '-cz'", implode("\n", $log));
        self::assertStringContainsString("'-u' 'web7' '-H' '--' 'rsync'", implode("\n", $log));
    }

    // In a dry run blog_public reads as empty, which is not 0: production stays public.
    public function testPushPutsBackTheSearchEngineSettingOfProduction(): void
    {
        $runner = new Runner(true);
        $this->sync([200, 200])->push($this->staging(), $this->production(), $runner);

        self::assertNotEmpty(self::lines($runner, "'option' 'get' 'blog_public'"));
        $updates = self::lines($runner, "'option' 'update' 'blog_public'");
        self::assertCount(1, $updates);
        self::assertStringContainsString("'blog_public' '1'", $updates[0]);
        self::assertStringContainsString("'-u' 'web7'", $updates[0]);
    }

    public function testAProductionBrokenByThePushIsRolledBack(): void
    {
        $result = $this->sync([200, 500, 500, 200])->push($this->staging(), $this->production(), new Runner(true));

        self::assertSame('rolled_back', $result['outcome']);
        self::assertSame(['backup web7', 'restore web7 20260101-000000'], $this->events);
        self::assertStringContainsString('home', $result['message']);
    }

    public function testARollbackThatDoesNotHelpIsReported(): void
    {
        $result = $this->sync([200, 500, 500])->push($this->staging(), $this->production(), new Runner(true));

        self::assertSame('rollback_failed', $result['outcome']);
    }

    // Not a dry run: the command really fails, because the WP-CLI path does not exist here.
    public function testAFailedRefreshBringsTheStagingBack(): void
    {
        $result = $this->sync([200], ['wp_cli' => '/nonexistent/wp'])->refresh($this->staging(), $this->production(), new Runner(false, [], 5));

        self::assertSame('failed', $result['outcome']);
        self::assertSame(['backup web8', 'restore web8 20260101-000000'], $this->events);
    }

    public function testTheTargetKeepsItsConfigAndLosesItsCache(): void
    {
        $runner = new Runner(true);
        $this->sync()->refresh($this->staging(), $this->production(), $runner);

        $tar = self::lines($runner, "'tar' '-cz'")[0];
        foreach (['./wp-config.php', './wp-cli.yml', './.maintenance', './wp-content/cache'] as $path) {
            self::assertStringContainsString("'--exclude={$path}'", $tar);
        }
        $rsync = self::lines($runner, "'rsync'")[0];
        foreach (['/wp-config.php', '/wp-cli.yml', '/.maintenance'] as $path) {
            self::assertStringContainsString("'--exclude={$path}'", $rsync);
        }
        self::assertStringNotContainsString('cache', $rsync);
    }

    public function testTheDatabaseTravelsBetweenTheTwoUsersWithoutClientCommands(): void
    {
        $runner = new Runner(true);
        $this->sync()->refresh($this->staging(), $this->production(), $runner);

        // The other mysql commands of a sync only receive SQL of this module, on stdin.
        $pipe = array_values(array_filter(self::lines($runner, '--binary-mode'), static fn(string $line): bool => str_contains($line, ' | ')));
        self::assertCount(1, $pipe);
        self::assertMatchesRegularExpression("/'-u' 'web7' '--preserve-env=MYSQL_PWD' '-H' '--' '(mysqldump|mariadb-dump)'.* \\| .*'-u' 'web8' '--preserve-env=MYSQL_PWD' '-H' '--' '(mysql|mariadb)'/", $pipe[0]);
        self::assertStringNotContainsString('--result-file', $pipe[0]);
    }

    // The code of both sites is not trusted.
    public function testEveryWpCliCommandRunsInTheSandboxOfItsSite(): void
    {
        $runner = new Runner(true);
        $this->sync([200, 200])->push($this->staging(), $this->production(), $runner);

        $wp = self::lines($runner, '--path=');
        self::assertNotEmpty($wp);
        foreach ($wp as $line) {
            self::assertStringContainsString('ffi.enable=0', $line);
            self::assertMatchesRegularExpression("#'-u' '(web\\d+)' .*open_basedir={$this->root}/client1/\\1:#", $line);
        }
    }

    /** @dataProvider unrelated */
    public function testOnlyAStagingAndItsOwnProductionArePaired(array $staging, array $production, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        SiteSync::assertPair($staging + $this->staging(), $production + $this->production());
    }

    public static function unrelated(): array
    {
        return [
            'not a staging' => [['staging_of' => 0], [], 'is not the staging of'],
            'the staging of another site' => [['staging_of' => 9], [], 'is not the staging of'],
            'production not installed' => [[], ['status' => 'error'], 'shop.test is not installed'],
            'staging not installed' => [['status' => 'queued'], [], 'staging.shop.test is not installed'],
            'another node' => [['server_id' => 2], [], 'is on another node'],
        ];
    }

    public function testNothingIsDoneForAWrongPair(): void
    {
        $runner = new Runner(true);
        try {
            $this->sync()->push(['staging_of' => 9] + $this->staging(), $this->production(), $runner);
            self::fail('expected a refusal');
        } catch (\RuntimeException) {
        }
        self::assertSame([], $this->events);
        self::assertSame([], $runner->log());
    }

    public function testBase(): void
    {
        self::assertSame('https://shop.test', SiteSync::base('https://shop.test/blog/'));
        self::assertSame('http://shop.test:8080', SiteSync::base('http://shop.test:8080'));
        $this->expectException(\RuntimeException::class);
        SiteSync::base('shop.test');
    }
}
