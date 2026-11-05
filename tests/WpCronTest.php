<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;
use WpManager\PathGuard;
use WpManager\Runner;
use WpManager\WpCron;

final class WpCronTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wpm-cron-' . bin2hex(random_bytes(4));
        mkdir($base . '/client1/web7/web', 0o755, true);
        $this->root = (string)realpath($base);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function site(): array
    {
        return ['document_root' => $this->root . '/client1/web7', 'system_user' => 'web7', 'domain' => 'a.test',
            'site_url' => 'https://a.test', 'server_id' => 1, 'domain_id' => 5];
    }

    private function cron(): WpCron
    {
        return new WpCron(Config::fromArray([]), new PathGuard($this->root));
    }

    public function testRealCronTurnsTheConstantOn(): void
    {
        $runner = new Runner(true);
        $this->cron()->apply($this->site(), WpCron::REAL, $runner);
        $log = implode("\n", $runner->log());

        self::assertStringContainsString("'config' 'set' 'DISABLE_WP_CRON' 'true' '--type=constant' '--raw'", $log);
        self::assertStringContainsString("'-u' 'web7'", $log);
    }

    public function testBackToWpCronRemovesTheLine(): void
    {
        $runner = new Runner(true);
        $this->cron()->apply($this->site(), WpCron::WORDPRESS, $runner);
        $log = implode("\n", $runner->log());

        self::assertStringContainsString("'config' 'delete' 'DISABLE_WP_CRON' '--type=constant'", $log);
        self::assertStringNotContainsString("'config' 'set'", $log);
    }

    public function testUnknownModeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cron()->apply($this->site(), 'evil', new Runner(true));
    }

    public function testJobCallsTheSiteEveryFiveMinutes(): void
    {
        $params = WpCron::jobParams($this->site());

        self::assertSame('url', $params['type']);
        self::assertSame('https://a.test/wp-cron.php?doing_wp_cron', $params['command']);
        self::assertSame('*/5', $params['run_min']);
        self::assertSame(5, $params['parent_domain_id']);
        self::assertSame('y', $params['active']);
    }

    public function testConstantIsRecognised(): void
    {
        self::assertTrue(WpCron::isDisabled(['exit' => 0, 'stdout' => "1\n", 'stderr' => '']));
        self::assertTrue(WpCron::isDisabled(['exit' => 0, 'stdout' => "true\n", 'stderr' => '']));
        self::assertFalse(WpCron::isDisabled(['exit' => 0, 'stdout' => "\n", 'stderr' => '']));
        self::assertFalse(WpCron::isDisabled(['exit' => 0, 'stdout' => "false\n", 'stderr' => '']));
        self::assertFalse(WpCron::isDisabled(['exit' => 1, 'stdout' => '', 'stderr' => 'Error: not found']));
    }
}
