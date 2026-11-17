<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation\Tests;

use PHPUnit\Framework\TestCase;
use WPStation\Config;
use WPStation\PathGuard;
use WPStation\Runner;
use WPStation\WpDebug;

final class WpDebugTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wpstation-debug-' . bin2hex(random_bytes(4));
        mkdir($base . '/client1/web7/web', 0o755, true);
        mkdir($base . '/client1/web7/private', 0o755, true);
        $this->root = (string)realpath($base);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function site(): array
    {
        return ['document_root' => $this->root . '/client1/web7', 'system_user' => 'web7', 'domain' => 'a.test'];
    }

    private function debug(): WpDebug
    {
        return new WpDebug(Config::fromArray([]), new PathGuard($this->root));
    }

    public function testOnLogsOutsideTheWebRootAndShowsNothingInThePages(): void
    {
        $runner = new Runner(true);
        $this->debug()->set($this->site(), true, $runner);
        $log = $runner->log();

        self::assertCount(3, $log);
        // WP_DEBUG last: with it alone, visitors would see the errors.
        self::assertStringContainsString("'config' 'set' 'WP_DEBUG_DISPLAY' 'false' '--type=constant' '--raw'", $log[0]);
        self::assertStringContainsString("'config' 'set' 'WP_DEBUG_LOG' '{$this->root}/client1/web7/private/wp-debug.log' '--type=constant'", $log[1]);
        self::assertStringContainsString("'config' 'set' 'WP_DEBUG' 'true' '--type=constant' '--raw'", $log[2]);
        foreach ($log as $line) {
            self::assertStringContainsString("'-u' 'web7'", $line);
            self::assertStringContainsString('ffi.enable=0', $line);
        }
    }

    public function testOnWithoutThePrivateFolderChangesNothing(): void
    {
        rmdir($this->root . '/client1/web7/private');
        $runner = new Runner(true);
        try {
            $this->debug()->set($this->site(), true, $runner);
            self::fail('expected an exception');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('private', $e->getMessage());
        }
        self::assertCount(1, $runner->log(), 'only WP_DEBUG_DISPLAY, which hides errors');
    }

    public function testOffTurnsTheConstantOffAndRemovesTheOthers(): void
    {
        $runner = new Runner(true);
        $this->debug()->set($this->site(), false, $runner);
        $log = implode("\n", $runner->log());

        self::assertStringContainsString("'config' 'set' 'WP_DEBUG' 'false' '--type=constant' '--raw'", $log);
        self::assertStringContainsString("'config' 'delete' 'WP_DEBUG_LOG' '--type=constant'", $log);
        self::assertStringContainsString("'config' 'delete' 'WP_DEBUG_DISPLAY' '--type=constant'", $log);
    }

    public function testTheLogIsReadAsTheSiteUser(): void
    {
        $runner = new Runner(true);
        self::assertSame('', $this->debug()->tail($this->site(), $runner));
        self::assertSame(["[dry-run] 'sudo' '-u' 'web7' '-H' '--' 'tail' '-n' '200' '--' '{$this->root}/client1/web7/private/wp-debug.log'"], $runner->log());
    }

    public function testALongLogKeepsItsEndFromTheStartOfALine(): void
    {
        $text = str_repeat("PHP Notice: something\n", 10000) . "last line\n";
        $tail = WpDebug::limited($text);

        self::assertLessThanOrEqual(WpDebug::MAX_TAIL, strlen($tail));
        self::assertStringStartsWith('PHP Notice', $tail);
        self::assertStringEndsWith("last line\n", $tail);
        self::assertTrue(mb_check_encoding(WpDebug::limited("a\xC3b"), 'UTF-8'), 'valid UTF-8 for the panel');
    }
}
