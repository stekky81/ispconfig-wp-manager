<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Command;
use WpManager\Config;
use WpManager\PathGuard;
use WpManager\Runner;
use WpManager\SiteDatabase;

final class SiteDatabaseTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wpm-db-' . bin2hex(random_bytes(4));
        mkdir($base . '/client1/web7/web', 0o755, true);
        $this->root = (string)realpath($base);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function database(): SiteDatabase
    {
        return new SiteDatabase(Config::fromArray([]), new PathGuard($this->root));
    }

    private function site(): array
    {
        return ['document_root' => $this->root . '/client1/web7', 'system_user' => 'web7'];
    }

    /** @param array<string, string> $constants */
    private static function json(array $constants): string
    {
        $rows = [['name' => 'table_prefix', 'value' => 'wp_', 'type' => 'variable']];
        foreach ($constants as $name => $value) {
            $rows[] = ['name' => $name, 'value' => $value, 'type' => 'constant'];
        }
        return json_encode($rows, JSON_THROW_ON_ERROR);
    }

    private const GOOD = ['DB_NAME' => 'c1_site', 'DB_USER' => 'c1_user', 'DB_PASSWORD' => 'p"w \'d', 'DB_HOST' => 'localhost', 'DB_CHARSET' => 'utf8mb4'];

    public function testSettingsAreReadFromTheConstants(): void
    {
        self::assertSame(
            ['name' => 'c1_site', 'user' => 'c1_user', 'password' => 'p"w \'d', 'host' => 'localhost', 'charset' => 'utf8mb4'],
            SiteDatabase::parseSettings(self::json(self::GOOD)),
        );
    }

    // wp-config.php is written by the site: what it prints or defines must not get further.
    /** @return array<string, array{string}> */
    public static function badSettings(): array
    {
        return [
            'printed text' => ["hello\n" . self::json(self::GOOD)],
            'no json' => [''],
            'no name' => [self::json(['DB_NAME' => ''] + self::GOOD)],
            'newline in the name' => [self::json(['DB_NAME' => "a\nb"] + self::GOOD)],
            'newline in the user' => [self::json(['DB_USER' => "a\nb"] + self::GOOD)],
            'zero byte in the host' => [self::json(['DB_HOST' => "a\0b"] + self::GOOD)],
            'zero byte in the password' => [self::json(['DB_PASSWORD' => "a\0b"] + self::GOOD)],
            'odd charset' => [self::json(['DB_CHARSET' => '../x'] + self::GOOD)],
        ];
    }

    /** @dataProvider badSettings */
    public function testBadSettingsAreRefused(string $json): void
    {
        $this->expectException(\RuntimeException::class);
        SiteDatabase::parseSettings($json);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function hosts(): array
    {
        return [
            'name' => ['localhost', ['--host=localhost']],
            'port' => ['db.test:3307', ['--host=db.test', '--port=3307', '--protocol=tcp']],
            'socket' => ['localhost:/run/mysqld/mysqld.sock', ['--host=localhost', '--socket=/run/mysqld/mysqld.sock']],
            'nothing after the colon' => ['localhost:', ['--host=localhost']],
            'ipv6' => ['::1', ['--host=::1']],
        ];
    }

    /**
     * @dataProvider hosts
     * @param list<string> $expected
     */
    public function testHostIsReadAsWordPressDoes(string $host, array $expected): void
    {
        self::assertSame($expected, SiteDatabase::hostArguments($host));
    }

    public function testToolsIgnoreTheOptionFilesOfTheSite(): void
    {
        $runner = new Runner(true);
        $this->database()->dump($this->site(), '/x/db.sql', $runner);
        $this->database()->load($this->site(), '/x/db.sql', $runner);

        $tools = array_filter($runner->log(), static fn(string $line): bool => str_contains($line, 'MYSQL_PWD'));
        self::assertCount(2, $tools);
        foreach ($tools as $line) {
            self::assertMatchesRegularExpression("/'(mysqldump|mysql|mariadb-dump|mariadb)' '--no-defaults'/", $line);
        }
    }

    // Regression: \! in a dump changed by the site user started a program during a restore.
    public function testALoadDoesNotObeyCommandsInTheDump(): void
    {
        $runner = new Runner(true);
        $this->database()->load($this->site(), '/x/db.sql', $runner);

        self::assertStringContainsString("'--binary-mode'", implode("\n", $runner->log()));
    }

    public function testSettingsAreReadInTheSandbox(): void
    {
        $runner = new Runner(true);
        $this->database()->dump($this->site(), '/x/db.sql', $runner);

        self::assertStringContainsString('ffi.enable=0', $runner->log()[0]);
        self::assertStringContainsString("'config' 'list'", $runner->log()[0]);
    }

    public function testThePasswordStaysOutOfTheArguments(): void
    {
        $command = new Command(['mysqldump'], 'web7', null, null, ['MYSQL_PWD' => 'secret-value']);

        self::assertStringNotContainsString('secret-value', implode(' ', $command->argv()));
        self::assertStringNotContainsString('secret-value', $command->toString());
        self::assertSame(['sudo', '-u', 'web7', '--preserve-env=MYSQL_PWD', '-H', '--', 'mysqldump'], $command->argv());
    }

    public function testTheCommandGetsItsEnvironment(): void
    {
        $command = new Command(['sh', '-c', 'printf %s "$WPM_TEST"'], null, null, null, ['WPM_TEST' => 'value']);

        self::assertSame('value', (new Runner(false))->mustRun($command));
    }
}
