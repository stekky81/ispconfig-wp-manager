<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;
use WpManager\Crypto;
use WpManager\PathGuard;
use WpManager\Runner;
use WpManager\SiteCloner;

final class SiteClonerTest extends TestCase
{
    private string $root;
    private Crypto $crypto;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wpm-clone-' . bin2hex(random_bytes(4));
        mkdir($base . '/client1/web7/web', 0o755, true);
        mkdir($base . '/client2/web9/web', 0o755, true);
        $this->root = (string)realpath($base);
        touch($this->root . '/client1/web7/web/wp-config.php');
        touch($this->root . '/client2/web9/web/standard_index.html');
        $this->crypto = new Crypto(base64_decode(Crypto::generateKey()));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function source(array $over = []): array
    {
        return $over + [
            'wpm_site_id' => 7, 'domain' => 'old.com', 'status' => 'installed', 'server_id' => 1,
            'document_root' => $this->root . '/client1/web7', 'system_user' => 'web7', 'db_name' => 'c1_old',
        ];
    }

    private function target(array $over = []): array
    {
        return $over + [
            'wpm_site_id' => 9, 'domain' => 'new.test', 'server_id' => 1, 'clone_of' => 7,
            'site_url' => 'http://new.test', 'document_root' => $this->root . '/client2/web9', 'system_user' => 'web9',
            'db_name' => 'c2_new', 'db_user' => 'c2_newu', 'db_host' => 'localhost',
            'secret_db' => $this->crypto->encrypt('DbPw123'),
        ];
    }

    private function cloner(): SiteCloner
    {
        return new SiteCloner(Config::fromArray([]), $this->crypto, new PathGuard($this->root));
    }

    private function log(): string
    {
        $runner = new Runner(true, ['DbPw123']);
        $this->cloner()->clone($this->target(), $this->source(), $runner);
        return implode("\n", $runner->log());
    }

    // The worst possible bug: the clone would use the database of the source.
    public function testTheConfigFileOfTheSourceIsNeverCopied(): void
    {
        self::assertStringContainsString("'--exclude=./wp-config.php'", $this->log());
    }

    public function testStepsRunInASafeOrder(): void
    {
        $log = $this->log();
        $last = -1;
        foreach (['standard_index.html', "'tar' '-cz", "'config' 'create'", "'config' 'get' 'DB_NAME'", "'db' 'export'", "'search-replace'"] as $step) {
            $at = strpos($log, $step);
            self::assertNotFalse($at, $step);
            self::assertGreaterThan($last, $at, $step);
            $last = $at;
        }
    }

    public function testTheSourceIsOnlyRead(): void
    {
        foreach (explode("\n", $this->log()) as $line) {
            foreach (explode(' | ', $line) as $command) {
                if (str_contains($command, "'web7'")) {
                    self::assertMatchesRegularExpression("/'tar' '-cz|'option' 'get'|'config' 'get'|'db' 'export'/", $command);
                }
            }
        }
    }

    public function testChangesRunAsTheUserOfTheClone(): void
    {
        foreach (explode("\n", $this->log()) as $line) {
            if (str_contains($line, "'search-replace'") || str_contains($line, "'config' 'create'")) {
                self::assertStringContainsString("'-u' 'web9'", $line);
            }
        }
    }

    public function testSearchReplaceCoversSerializedDataEverywhere(): void
    {
        $log = $this->log();

        self::assertStringContainsString("'--regex'", $log);
        self::assertStringContainsString("'--all-tables'", $log);
        self::assertStringContainsString("'--skip-columns=guid'", $log);
        self::assertStringContainsString("'--regex-delimiter=#'", $log);
    }

    // Regression: the status message is cut at 4000 characters, which hid the summary at the end.
    public function testTheSummaryComesBeforeTheCommands(): void
    {
        $log = $this->cloner()->clone($this->target(), $this->source(), new Runner(true));

        self::assertStringStartsWith('Replaced ', $log[0]);
    }

    public function testNoSecretInTheLog(): void
    {
        self::assertStringNotContainsString('DbPw123', $this->log());
    }

    public function testATargetThatIsNotEmptyIsRefused(): void
    {
        file_put_contents($this->root . '/client2/web9/web/index.php', '<?php');
        $this->expectExceptionMessage('Refusing to install');
        $this->cloner()->clone($this->target(), $this->source(), new Runner(true));
    }

    public function testASourceWithoutWordpressIsRefused(): void
    {
        unlink($this->root . '/client1/web7/web/wp-config.php');
        $this->expectExceptionMessage('no WordPress');
        $this->cloner()->clone($this->target(), $this->source(), new Runner(true));
    }

    /** @dataProvider badSources */
    public function testBadSourcesAreRefused(array $over, string $message): void
    {
        $this->expectExceptionMessage($message);
        $this->cloner()->clone($this->target(), $this->source($over), new Runner(true));
    }

    public static function badSources(): array
    {
        return [
            'not installed' => [['status' => 'error'], 'not installed'],
            'other node' => [['server_id' => 2], 'another node'],
            'same site' => [['wpm_site_id' => 9], 'itself'],
            'same database' => [['db_name' => 'c2_new'], 'same database'],
        ];
    }

    // Second line of defence: wp-config.php of the clone must point to the database of the clone.
    public function testDatabaseGuardRefusesTheWrongDatabase(): void
    {
        $this->expectExceptionMessage('wrong database');
        SiteCloner::assertOwnDatabase('c1_old', 'c2_new', 'c1_old');
    }

    public function testDatabaseGuardRefusesAnUnknownDatabase(): void
    {
        $this->expectExceptionMessage('wrong database');
        SiteCloner::assertOwnDatabase("\n", 'c2_new', 'c1_old');
    }

    public function testDatabaseGuardAcceptsTheOwnDatabase(): void
    {
        SiteCloner::assertOwnDatabase("c2_new\n", 'c2_new', 'c1_old');
        self::assertTrue(true);
    }

    // Regression: guid is left alone on purpose, and counting it warned about every clone.
    public function testGuidsDoNotCountAsMentions(): void
    {
        $output = "wp_posts:guid:1:http://old.com/?p=1\nwp_options:option_value:9:info@old.com\nxy9_posts:guid:2:http://old.com/?p=2\n";

        self::assertSame(1, SiteCloner::countMentions($output));
        self::assertSame(0, SiteCloner::countMentions("\n"));
    }

    public function testTablePrefixOfTheSourceIsKept(): void
    {
        self::assertSame('abc_', SiteCloner::tablePrefix("abc_\n"));
        self::assertSame('wp_', SiteCloner::tablePrefix(''));
    }

    public function testAnUnsafeTablePrefixIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        SiteCloner::tablePrefix("wp_'; DROP");
    }
}
