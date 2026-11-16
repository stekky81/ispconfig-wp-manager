<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;
use WpManager\MaintenanceMode;
use WpManager\PathGuard;
use WpManager\Runner;

final class MaintenanceModeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wpm-maint-' . bin2hex(random_bytes(4));
        mkdir($base . '/client1/web7/web', 0o755, true);
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

    private function mode(): MaintenanceMode
    {
        return new MaintenanceMode(Config::fromArray([]), new PathGuard($this->root));
    }

    /** What WordPress finds in $upgrading after it includes the file, as wp_is_maintenance() does. */
    private function upgrading(array $cookies, string $script): int
    {
        $file = $this->root . '/maintenance.php';
        file_put_contents($file, MaintenanceMode::fileContents());
        [$savedCookies, $savedScript] = [$_COOKIE, $_SERVER['SCRIPT_NAME'] ?? null];
        $_COOKIE = $cookies;
        $_SERVER['SCRIPT_NAME'] = $script;
        try {
            return (static function (string $file): int {
                require $file;
                /** @var int $upgrading */
                return $upgrading;
            })($file);
        } finally {
            $_COOKIE = $savedCookies;
            $_SERVER['SCRIPT_NAME'] = $savedScript;
        }
    }

    public function testVisitorsGetTheMaintenancePageForAsLongAsTheFileIsThere(): void
    {
        // WordPress ignores a $upgrading older than 10 minutes: this one is always now.
        self::assertGreaterThanOrEqual(time() - 1, $this->upgrading([], '/index.php'));
        self::assertGreaterThanOrEqual(time() - 1, $this->upgrading(['wordpress_test_cookie' => 'WP Cookie check', 'comment_author_x' => 'a'], '/index.php'));
        self::assertGreaterThanOrEqual(time() - 1, $this->upgrading([], '/wp-admin/index.php'));
    }

    public function testLoggedInUsersAndTheLoginPageSeeTheSite(): void
    {
        self::assertSame(0, $this->upgrading(['wordpress_logged_in_0123abcd' => 'x'], '/index.php'));
        self::assertSame(0, $this->upgrading([], '/wp-login.php'));
        self::assertSame(0, $this->upgrading([MaintenanceMode::BYPASS_COOKIE => '1'], '/'));
    }

    public function testTheFileIsMarkedAsOurs(): void
    {
        self::assertStringContainsString(MaintenanceMode::MARKER, MaintenanceMode::fileContents());
        self::assertStringStartsWith('<?php', MaintenanceMode::fileContents());
    }

    public function testOnWritesTheFileAsTheSiteUser(): void
    {
        $runner = new Runner(true);
        $this->mode()->set($this->site(), true, $runner);

        self::assertSame(["[dry-run] 'sudo' '-u' 'web7' '-H' '--' 'tee' '--' '{$this->root}/client1/web7/web/.maintenance'"], $runner->log());
    }

    public function testOffRemovesTheFile(): void
    {
        $runner = new Runner(true);
        $this->mode()->set($this->site(), false, $runner);

        self::assertSame(["[dry-run] 'sudo' '-u' 'web7' '-H' '--' 'rm' '-f' '--' '{$this->root}/client1/web7/web/.maintenance'"], $runner->log());
    }

    public function testADryRunReadsNothing(): void
    {
        $runner = new Runner(true);
        self::assertFalse($this->mode()->isOn($this->site(), $runner));
        self::assertSame([], $runner->log());
    }

    public function testASiteOutsideTheWebRootsIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->mode()->set(['document_root' => sys_get_temp_dir(), 'system_user' => 'web7'], true, new Runner(true));
    }
}
