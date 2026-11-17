<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation\Tests;

use PHPUnit\Framework\TestCase;
use WPStation\Config;
use WPStation\PathGuard;
use WPStation\Runner;
use WPStation\SiteUsers;
use WPStation\ValidationException;

final class SiteUsersTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wpstation-users-' . bin2hex(random_bytes(4));
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

    private function users(): SiteUsers
    {
        return new SiteUsers(Config::fromArray([]), new PathGuard($this->root));
    }

    public function testAdministratorsAreReadFromWpCliWithoutTheTemporaryOnes(): void
    {
        $output = "PHP Notice: something\n" . json_encode([
            ['ID' => 1, 'user_login' => 'madmin', 'roles' => 'administrator'],
            ['ID' => '7', 'user_login' => 'wpstation-login-0a1b2c3d'],
            ['ID' => 9, 'user_login' => 'mario@example.com'],
            ['ID' => 0, 'user_login' => 'nobody'],
            ['ID' => 4, 'user_login' => ['not', 'a', 'string']],
            'garbage',
        ]);

        self::assertSame([['id' => 1, 'login' => 'madmin'], ['id' => 9, 'login' => 'mario@example.com']], SiteUsers::parseAdmins($output));
        self::assertSame([], SiteUsers::parseAdmins('Error: not a WordPress site'));
    }

    public function testOnlyTheNamesOfThisModuleAreTemporary(): void
    {
        self::assertTrue(SiteUsers::isTemporary('wpstation-login-0a1b2c3d'));
        self::assertFalse(SiteUsers::isTemporary('wpstation-login-admin'));
        self::assertFalse(SiteUsers::isTemporary('madmin'));
        self::assertFalse(SiteUsers::isTemporary('wpstation-login-0a1b2c3d '));
    }

    public function testATemporaryLoginIsAnAdministratorWithThePasswordOnStdin(): void
    {
        $runner = new Runner(true);
        $login = $this->users()->createLogin($this->site(), '', $runner);
        $log = implode("\n", $runner->log());

        self::assertTrue(SiteUsers::isTemporary($login['user']));
        self::assertSame(24, strlen($login['password']));
        self::assertStringContainsString("'user' 'create' '{$login['user']}' '{$login['user']}@example.invalid' '--role=administrator'", $log);
        self::assertStringContainsString("'--prompt=user_pass'", $log);
        self::assertStringContainsString("'--skip-plugins'", $log);
        self::assertStringContainsString('ffi.enable=0', $log);
        self::assertStringNotContainsString($login['password'], $log);
    }

    public function testANewLoginReplacesThePreviousOne(): void
    {
        $runner = new Runner(true);
        $this->users()->createLogin($this->site(), 'wpstation-login-0a1b2c3d', $runner);
        $log = $runner->log();

        // In a dry run there is no other administrator: removed by taking its role away.
        $previous = array_keys(array_filter($log, static fn(string $l): bool => str_contains($l, "'wpstation-login-0a1b2c3d'")));
        $create = array_keys(array_filter($log, static fn(string $l): bool => str_contains($l, "'user' 'create'")));
        self::assertNotEmpty($previous);
        self::assertCount(1, $create);
        self::assertTrue(max($previous) < $create[0]);
    }

    public function testOnlyATemporaryLoginCanBeRemoved(): void
    {
        $runner = new Runner(true);
        try {
            // A node can write login_user: never a way to delete a real administrator.
            $this->users()->removeLogin($this->site(), 'madmin', $runner);
            self::fail('expected an exception');
        } catch (ValidationException) {
        }
        self::assertSame([], $runner->log());
        $this->expectException(ValidationException::class);
        $this->users()->hasSession($this->site(), '1', $runner);
    }

    public function testWithoutAnotherAdministratorTheTemporaryUserLosesItsRoleAndPassword(): void
    {
        // In a dry run the list of administrators is empty.
        $runner = new Runner(true);
        $done = $this->users()->removeLogin($this->site(), 'wpstation-login-0a1b2c3d', $runner);
        $log = implode("\n", $runner->log());

        self::assertStringContainsString('subscriber', $done);
        self::assertStringNotContainsString("'user' 'delete'", $log);
        self::assertStringContainsString("'user' 'set-role' 'wpstation-login-0a1b2c3d' 'subscriber'", $log);
        self::assertStringContainsString("'user' 'session' 'destroy' 'wpstation-login-0a1b2c3d' '--all'", $log);
    }

    public function testAResetClosesEverySessionOfTheUser(): void
    {
        $runner = new Runner(true);
        $reset = $this->users()->resetPassword($this->site(), 3, $runner);
        $log = implode("\n", $runner->log());

        self::assertStringContainsString("'user' 'list' '--role=administrator'", $log);
        self::assertStringContainsString("'user' 'update' '3' '--skip-email' '--prompt=user_pass'", $log);
        self::assertStringContainsString("'user' 'session' 'destroy' '3' '--all'", $log);
        self::assertStringNotContainsString($reset['password'], $log);
    }
}
