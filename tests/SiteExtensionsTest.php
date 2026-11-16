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
use WpManager\SiteExtensions;
use WpManager\ValidationException;

final class SiteExtensionsTest extends TestCase
{
    private string $root;
    /** @var list<string> */
    public array $events = [];

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wpm-ext-' . bin2hex(random_bytes(4));
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

    /** @param list<int> $homeCodes one answer per health check */
    private function extensions(array $homeCodes): SiteExtensions
    {
        $i = 0;
        $fetch = static function (string $url) use (&$i, $homeCodes): array {
            if (str_ends_with($url, '/wp-login.php')) {
                return ['code' => 200, 'body' => ''];
            }
            return ['code' => $homeCodes[min($i++, count($homeCodes) - 1)], 'body' => ''];
        };
        $backups = new class ($this) implements SiteBackups {
            public function __construct(private readonly SiteExtensionsTest $test)
            {
            }
            public function create(array $site, ?Runner $runner = null): string
            {
                $this->test->events[] = 'backup';
                return '20260101-000000';
            }
            public function restore(array $site, string $id, ?Runner $runner = null): void
            {
                $this->test->events[] = "restore {$id}";
            }
        };
        return new SiteExtensions(Config::fromArray([]), new PathGuard($this->root), $backups, new HealthCheck($fetch, '127.0.0.1', 0));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function states(): array
    {
        return [
            'activate an inactive plugin' => ['plugin_activate', 'inactive', true],
            'activate an active plugin' => ['plugin_activate', 'active', false],
            'activate a must-use plugin' => ['plugin_activate', 'must-use', false],
            'deactivate an active plugin' => ['plugin_deactivate', 'active', true],
            'deactivate a network plugin' => ['plugin_deactivate', 'active-network', false],
            'remove an active plugin' => ['plugin_delete', 'active', true],
            'remove a drop-in' => ['plugin_delete', 'dropin', false],
            'remove a missing plugin' => ['plugin_delete', '', false],
            'activate a parent theme' => ['theme_activate', 'parent', true],
            'activate the active theme' => ['theme_activate', 'active', false],
            'remove an inactive theme' => ['theme_delete', 'inactive', true],
            'remove the active theme' => ['theme_delete', 'active', false],
            'remove the parent of the active theme' => ['theme_delete', 'parent', false],
            'an unknown action' => ['plugin_install', 'inactive', false],
        ];
    }

    /** @dataProvider states */
    public function testWhatEachActionAccepts(string $action, string $status, bool $allowed): void
    {
        self::assertSame($allowed, SiteExtensions::refusal($action, $status) === '');
    }

    public function testActivationRunsTheCodeOfThePluginInTheSandbox(): void
    {
        $runner = new Runner(true);
        $result = $this->extensions([200, 200])->run($this->site(), 'plugin_activate', 'akismet', $runner);

        self::assertSame('done', $result['outcome']);
        self::assertSame([], $this->events, 'no backup for an activation');
        $log = implode("\n", $runner->log());
        self::assertStringContainsString("'plugin' 'activate' 'akismet' '--path=", $log);
        foreach ($runner->log() as $line) {
            self::assertStringContainsString('ffi.enable=0', $line);
            self::assertStringContainsString("'-u' 'web7'", $line);
        }
    }

    public function testAnActivationThatBreaksTheSiteIsUndone(): void
    {
        $runner = new Runner(true);
        $result = $this->extensions([200, 500, 500, 200])->run($this->site(), 'plugin_activate', 'akismet', $runner);

        self::assertSame('rolled_back', $result['outcome']);
        self::assertStringContainsString('Broken after the activation of the plugin akismet', $result['message']);
        // Without loading the plugin that broke WordPress.
        self::assertStringContainsString("'plugin' 'deactivate' 'akismet' '--skip-plugins' '--skip-themes'", implode("\n", $runner->log()));
    }

    public function testRemovalTakesABackupAndRestoresItIfTheSiteBreaks(): void
    {
        $runner = new Runner(true);
        $result = $this->extensions([200, 500, 500, 200])->run($this->site(), 'plugin_delete', 'hello-dolly', $runner);

        self::assertSame('rolled_back', $result['outcome']);
        self::assertSame(['backup', 'restore 20260101-000000'], $this->events);
        self::assertStringContainsString("'-u' 'web7' '-H' '--' 'rm' '-rf' '--' '{$this->root}/client1/web7/web/wp-content/plugins/hello-dolly'", implode("\n", $runner->log()));
    }

    public function testAHealthyRemovalKeepsTheBackup(): void
    {
        $result = $this->extensions([200, 200])->run($this->site(), 'theme_delete', 'twentytwentytwo', new Runner(true));

        self::assertSame('done', $result['outcome']);
        self::assertSame(['backup'], $this->events);
        self::assertStringContainsString('Backup 20260101-000000 kept', $result['message']);
    }

    public function testASiteBrokenBeforeIsNotBlamedOnTheAction(): void
    {
        // Deactivating the plugin that breaks the site is the point.
        $result = $this->extensions([500, 500])->run($this->site(), 'plugin_deactivate', 'broken', new Runner(true));

        self::assertSame('done', $result['outcome']);
    }

    // Regression (VM): `wp plugin delete` starts rm, which the sandbox forbids, so no plugin could be removed.
    public function testAPluginIsRemovedByRmAsTheSiteUser(): void
    {
        $plugins = $this->root . '/client1/web7/web/wp-content/plugins';
        mkdir($plugins . '/akismet', 0o755, true);
        touch($plugins . '/akismet/akismet.php');
        touch($plugins . '/hello.php');
        $extensions = $this->extensions([200]);

        self::assertSame($plugins . '/akismet', $extensions->pluginFiles($this->site(), 'akismet', $plugins . '/akismet/akismet.php'));
        // A plugin of one file: never the folder of all plugins.
        self::assertSame($plugins . '/hello.php', $extensions->pluginFiles($this->site(), 'hello', $plugins . '/hello.php'));
    }

    /** @return array<string, array{string, string}> */
    public static function strangePlaces(): array
    {
        return [
            'outside the site' => ['akismet', '/etc/passwd'],
            'missing' => ['akismet', '/nowhere/akismet/akismet.php'],
            'another name' => ['akismet', 'PLUGINS/hello.php'],
            'the web root' => ['web', 'WEB/index.php'],
        ];
    }

    /** @dataProvider strangePlaces */
    public function testAStrangePlaceIsRefused(string $slug, string $path): void
    {
        $web = $this->root . '/client1/web7/web';
        mkdir($web . '/wp-content/plugins', 0o755, true);
        touch($web . '/wp-content/plugins/hello.php');
        touch($web . '/index.php');
        $path = str_replace(['PLUGINS', 'WEB'], [$web . '/wp-content/plugins', $web], $path);

        $this->expectException(\RuntimeException::class);
        $this->extensions([200])->pluginFiles($this->site(), $slug, $path);
    }

    public function testNamesThatCouldBeOptionsAreRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->extensions([200])->run($this->site(), 'plugin_activate', '--require=/tmp/x.php', new Runner(true));
    }
}
