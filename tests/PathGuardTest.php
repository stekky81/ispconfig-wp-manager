<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\PathGuard;

final class PathGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wpm-clients-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/client1/web1/web', 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testFreshIspconfigWebRootIsPristine(): void
    {
        $web = $this->root . '/client1/web1/web';
        foreach (['index.html', 'favicon.ico', 'robots.txt'] as $f) {
            touch("{$web}/{$f}");
        }
        mkdir("{$web}/stats");
        self::assertTrue((new PathGuard($this->root))->isPristineWebRoot($web));
    }

    // Regression: ISPConfig 3.3 creates standard_index.html, not index.html, in a new web root.
    public function testIspconfig33StandardIndexIsPristine(): void
    {
        $web = $this->root . '/client1/web1/web';
        foreach (['standard_index.html', 'favicon.ico', 'robots.txt'] as $f) {
            touch("{$web}/{$f}");
        }
        self::assertTrue((new PathGuard($this->root))->isPristineWebRoot($web));
    }

    public function testAnyOtherFileMakesItNotPristine(): void
    {
        $web = $this->root . '/client1/web1/web';
        foreach (['wp-config.php', '.htaccess', 'index.php', 'uploads'] as $f) {
            touch("{$web}/{$f}");
            self::assertFalse((new PathGuard($this->root))->isPristineWebRoot($web), $f);
            unlink("{$web}/{$f}");
        }
    }

    public function testPathsOutsideRootAreRejected(): void
    {
        $guard = new PathGuard($this->root);
        foreach (['/etc', $this->root . '/../', $this->root . '-other', '/nonexistent/path'] as $path) {
            try {
                $guard->assertInside($path);
                self::fail("Accepted: {$path}");
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSymlinkEscapeIsRejected(): void
    {
        $link = $this->root . '/client1/web1/web/escape';
        symlink('/etc', $link);
        $this->expectException(\RuntimeException::class);
        (new PathGuard($this->root))->assertInside($link);
    }
}
