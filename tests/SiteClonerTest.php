<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation\Tests;

use PHPUnit\Framework\TestCase;
use WPStation\Config;
use WPStation\Crypto;
use WPStation\PathGuard;
use WPStation\Runner;
use WPStation\SearchReplacePlan;
use WPStation\SiteTransfer;
use WPStation\SiteCloner;

final class SiteClonerTest extends TestCase
{
    private string $root;
    private Crypto $crypto;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wpstation-clone-' . bin2hex(random_bytes(4));
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
            'wpstation_site_id' => 7, 'domain' => 'old.com', 'status' => 'installed', 'server_id' => 1,
            'document_root' => $this->root . '/client1/web7', 'system_user' => 'web7', 'db_name' => 'c1_old',
        ];
    }

    private function target(array $over = []): array
    {
        return $over + [
            'wpstation_site_id' => 9, 'domain' => 'new.test', 'server_id' => 1, 'clone_of' => 7,
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
    // The code of the source runs in WP-CLI of both sites: always in the sandbox, which starts no program.
    public function testEveryWpCliCommandRunsInTheSandboxOfItsSite(): void
    {
        $wp = array_filter(explode("\n", $this->log()), static fn(string $line): bool => str_contains($line, "'/usr/local/bin/wp'"));
        self::assertNotEmpty($wp);
        foreach ($wp as $line) {
            self::assertStringContainsString("'ffi.enable=0'", $line);
            self::assertMatchesRegularExpression("#'-u' '(web\\d+)' .*open_basedir={$this->root}/client\\d/\\1:#", $line);
            self::assertStringNotContainsString("'db' 'export'", $line);
            self::assertStringNotContainsString("'db' 'import'", $line);
        }
    }

    private function remote(): SiteTransfer
    {
        $store = new MemoryTransferStore();
        $id = $store->open(7, 9, 1, 2);
        $crypto = new \WPStation\Crypto(base64_decode(\WPStation\Crypto::generateKey()));
        (new SiteTransfer($store, $crypto, $id, 0, static function (): void {
        }))->sendMeta(['home' => 'http://old.com', 'prefix' => 'xy_']);
        // The cloner decrypts the database password with its own Crypto: only the transfer uses this one.
        return new SiteTransfer($store, $crypto, $id, 0, static function (): void {
        });
    }

    // The folder of a source on another node is not on this machine: nothing of it is read here.
    public function testASourceOnAnotherNodeArrivesThroughTheTransfer(): void
    {
        $runner = new Runner(true);
        // A folder that does not exist on this node.
        $source = $this->source(['server_id' => 2, 'document_root' => '/var/www/clients/client9/web99']);
        $this->cloner()->clone($this->target(['server_id' => 1]), $source, $runner, false, $this->remote());
        $log = implode("\n", $runner->log());

        self::assertStringContainsString("[dry-run] (transfer) | 'sudo' '-u' 'web9' '-H' '--' 'tar' '-xz'", $log);
        self::assertStringNotContainsString("'web7'", $log);
        // Its folder is only the text that the replace changes.
        foreach (explode("\n", $log) as $line) {
            if (!str_contains($line, "'search-replace'")) {
                self::assertStringNotContainsString('web99', $line);
            }
        }
        // The table prefix of the description.
        self::assertStringContainsString("'--dbprefix=xy_'", $log);
    }

    public function testATransferIsOnlyForASourceOnAnotherNode(): void
    {
        $this->expectExceptionMessage('is on this node');
        $this->cloner()->clone($this->target(), $this->source(), new Runner(true), false, $this->remote());
    }

    public function testTheSourceNodeSendsSettingsFilesAndDatabase(): void
    {
        $store = new MemoryTransferStore();
        $id = $store->open(7, 9, 1, 2);
        $runner = new Runner(true);
        $out = new SiteTransfer($store, new \WPStation\Crypto(base64_decode(\WPStation\Crypto::generateKey())), $id, 0, static function (): void {
        });
        try {
            $this->cloner()->export($this->source(), $out, $runner);
        } catch (\RuntimeException) {
            // A dry run reads no settings: the description cannot be built.
        }
        $log = implode("\n", $runner->log());
        self::assertStringContainsString("'option' 'get' 'home'", $log);
        foreach (explode("\n", $log) as $line) {
            self::assertStringContainsString("'-u' 'web7'", $line, 'everything as the source user');
        }
    }

    public function testTheConfigFileOfTheSourceIsNeverCopied(): void
    {
        self::assertStringContainsString("'--exclude=./wp-config.php'", $this->log());
    }

    // Regression: WP-CLI reads these files, so the owner of the source could run code in the clone.
    public function testWpCliConfigFilesAreNotCopied(): void
    {
        self::assertStringContainsString("'--exclude=./wp-cli.yml'", $this->log());
        self::assertStringContainsString("'--exclude=./wp-cli.local.yml'", $this->log());
    }

    public function testAHostileHomeOptionFallsBackToTheDomain(): void
    {
        self::assertSame('http://old.com', SiteCloner::safeUrl('http://--require=evil.php', 'old.com'));
        self::assertSame('https://www.old.com/blog', SiteCloner::safeUrl('https://www.old.com/blog', 'old.com'));
        self::assertSame('http://old.com', SiteCloner::safeUrl('', 'old.com'));
    }

    // Regression: the replace cannot match a hostile value, which stayed in the clone.
    public function testTheAddressOfTheCloneIsAlwaysSet(): void
    {
        $log = $this->log();

        self::assertStringContainsString("'option' 'update' 'home' 'http://new.test'", $log);
        self::assertStringContainsString("'option' 'update' 'siteurl' 'http://new.test'", $log);
    }

    public function testStepsRunInASafeOrder(): void
    {
        $log = $this->log();
        $last = -1;
        foreach (['standard_index.html', "'tar' '-cz", "'config' 'create'", "'config' 'get' 'DB_NAME'", "'--single-transaction'", "'search-replace'"] as $step) {
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
                    self::assertMatchesRegularExpression("/'tar' '-cz|'option' 'get'|'config' 'get'|'config' 'list'|'(mysqldump|mariadb-dump)'/", $command);
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

    // After the replace, so that nothing can turn it back on; only on the clone.
    public function testTheCloneDiscouragesSearchEngines(): void
    {
        $log = $this->log();
        $at = strpos($log, "'option' 'update' 'blog_public' '0'");

        self::assertNotFalse($at);
        self::assertGreaterThan(strrpos($log, "'search-replace'"), $at);
        $line = (string)current(array_filter(explode("\n", $log), static fn(string $l): bool => str_contains($l, 'blog_public')));
        self::assertStringContainsString("'-u' 'web9'", $line);
    }

    public function testNoSecretInTheLog(): void
    {
        self::assertStringNotContainsString('DbPw123', $this->log());
    }

    // Regression (VM): a retry refused the files of its own first attempt.
    public function testAResumedCloneRunsAgainOverItsOwnFiles(): void
    {
        file_put_contents($this->root . '/client2/web9/web/index.php', '<?php // first attempt');
        $runner = new Runner(true);
        $this->cloner()->clone($this->target(), $this->source(), $runner, true);

        self::assertMatchesRegularExpression("/'config' 'create' .*'--force'/", implode("\n", $runner->log()));
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
            'same site' => [['wpstation_site_id' => 9], 'itself'],
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

    public function testTheReportSaysWhereTheMentionsAre(): void
    {
        $output = "wp_posts:guid:1:http://old.com/?p=1\nwp_options:option_value:412:a:1:{s:4:\"rate\"...old.com\n"
            . "wp_users:user_email:1:info@old.com\nwp_users:user_email:2:x@old.com\nbad name:col:3:old.com\n";

        self::assertSame('wp_options.option_value (row 412), wp_users.user_email (row 1), wp_users.user_email (row 2)', SiteCloner::mentionPlaces($output));
        self::assertSame('wp_options.option_value (row 412) and others', SiteCloner::mentionPlaces($output, null, 1));
        self::assertSame('', SiteCloner::mentionPlaces(''));
    }

    // Regression (VM): the new address of staging.old.com counted as a mention of old.com.
    public function testALongerHostThatEndsWithTheOldOneIsNoMention(): void
    {
        $plan = new SearchReplacePlan('http://old.com', 'http://staging.old.com', '/a', '/b');
        $output = "wp_options:option_value:2:http://staging.old.com\n"
            . "wp_options:option_value:3:http://staging.old.com/x [...] ://staging.old.com\n"
            . "wp_posts:post_content:7:mail info@old.com now\n"
            . "wp_posts:post_content:8:http://staging.old.com/ and http://old.com/\n"
            . "wp_posts:post_content:9:see www.old.com.\n"
            . "wp_posts:post_content:10:WWW.OLD.COM\n"
            . "wp_posts:post_content:11:old.com is ours\n"
            . "wp_posts:post_content:12:http://myold.com and old.com.au and old.company.it\n";

        self::assertSame(5, SiteCloner::countMentions($output, $plan));
        self::assertSame('wp_posts.post_content (row 7), wp_posts.post_content (row 8), wp_posts.post_content (row 9) and others', SiteCloner::mentionPlaces($output, $plan));
    }

    public function testTheFinalSearchShowsTheCharactersAroundEachMatch(): void
    {
        self::assertStringContainsString("'db' 'search' 'old.com' '--all-tables' '--one_line' '--before_context=8' '--after_context=8'", $this->log());
    }

    // Regression (VM, Elementor): a cache of built pages kept the old address in base64.
    public function testCachesAreDeletedAfterTheReplaceAndBeforeTheFinalSearch(): void
    {
        $lines = explode("\n", $this->log());
        $mysql = array_keys(array_filter($lines, static fn(string $l): bool => str_contains($l, "'--batch'")));
        $replace = array_keys(array_filter($lines, static fn(string $l): bool => str_contains($l, "'search-replace'")));
        $search = array_keys(array_filter($lines, static fn(string $l): bool => str_contains($l, "'db' 'search'")));

        // The objects of plugins: the schema, then the caches.
        self::assertCount(2, $mysql);
        self::assertGreaterThan(max($replace), min($mysql));
        self::assertLessThan(min($search), max($mysql));
        foreach ($mysql as $i) {
            self::assertStringContainsString("'-u' 'web9'", $lines[$i]);
            self::assertStringContainsString("'--binary-mode'", $lines[$i]);
        }
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

    public function testOnlyAnAdminClonesToAnotherClient(): void
    {
        self::assertTrue(SiteCloner::mayCloneToClient('admin', 1, 2));
        self::assertFalse(SiteCloner::mayCloneToClient('user', 1, 2));
        self::assertFalse(SiteCloner::mayCloneToClient('', 1, 2));
    }

    public function testAClientClonesInsideTheirOwnAccount(): void
    {
        self::assertTrue(SiteCloner::mayCloneToClient('user', 1, 1));
        self::assertTrue(SiteCloner::mayCloneToClient('admin', 1, 1));
    }
}
