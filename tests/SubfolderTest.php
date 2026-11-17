<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;
use WpManager\Crypto;
use WpManager\DryRunTransport;
use WpManager\IspconfigApi;
use WpManager\PathGuard;
use WpManager\Runner;
use WpManager\SiteBackup;
use WpManager\SiteCloner;
use WpManager\SiteCredentials;
use WpManager\SitePermissions;
use WpManager\SiteProvisioner;
use WpManager\SiteRecord;
use WpManager\SiteRemoval;
use WpManager\SiteRequest;
use WpManager\SiteScanner;
use WpManager\Subfolder;
use WpManager\ValidationException;
use WpManager\WordPressInstaller;
use WpManager\WpCli;
use WpManager\WpDebug;
use WpManager\HealthCheck;

/** Sites in a folder of an existing website (example.com/blog), and what they change for the site in web/. */
final class SubfolderTest extends TestCase
{
    private string $root;
    private string $site;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wpm-sub-' . bin2hex(random_bytes(4));
        mkdir($base);
        $this->root = (string)realpath($base);
        $this->site = $this->root . '/client1/web7';
        mkdir($this->site . '/web', 0o755, true);
        mkdir($this->site . '/tmp');
        mkdir($this->site . '/private');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function record(string $subfolder = ''): array
    {
        return [
            'wpm_site_id' => 5,
            'domain' => 'example.com',
            'subfolder' => $subfolder,
            'document_root' => $this->site,
            'system_user' => 'web7',
            'domain_id' => 9,
            'server_id' => 1,
        ];
    }

    private function guard(): PathGuard
    {
        return new PathGuard($this->root);
    }

    private function wordpressIn(string $folder): void
    {
        mkdir("{$this->site}/web/{$folder}", 0o755, true);
        touch("{$this->site}/web/{$folder}/wp-config.php");
    }

    /** @return list<string> */
    public static function badNewNames(): array
    {
        return [['Blog'], ['a/b'], ['../x'], ['.hidden'], ['-blog'], ['blog-'], ['wp-admin'], ['stats'], ['my blog'], ['']];
    }

    /** @dataProvider badNewNames */
    public function testANewFolderIsOneSimpleNameNotUsedByIspconfigOrWordPress(string $name): void
    {
        $this->expectException(ValidationException::class);
        Subfolder::forNewSite($name);
    }

    public function testNamesOfExistingFoldersMayHaveCapitalsAndDotsButNeverAPath(): void
    {
        self::assertSame('blog', Subfolder::forNewSite(' /blog/ '));
        self::assertSame('Old_Site.v2', Subfolder::name('Old_Site.v2'));
        foreach (['..', 'a..b', 'a/b', '.git', 'WP-Content', ''] as $bad) {
            self::assertFalse(Subfolder::isValid($bad), $bad);
        }
    }

    public function testTheWebRootIsWebOrTheFolderBelowIt(): void
    {
        mkdir("{$this->site}/web/blog");
        self::assertSame("{$this->site}/web", Subfolder::webRoot($this->record(), $this->guard()));
        self::assertSame("{$this->site}/web/blog", Subfolder::webRoot($this->record('blog'), $this->guard()));
        self::assertSame('example.com/blog', Subfolder::label($this->record('blog')));
        self::assertSame('http://example.com/blog', Subfolder::siteUrl($this->record('blog')));
        self::assertSame('https://example.com/blog', Subfolder::siteUrl($this->record('blog') + ['site_url' => 'https://example.com/blog/']));
    }

    public function testAFolderThatIsALinkIsRefusedEvenInsideTheRoot(): void
    {
        mkdir($this->root . '/client2/web8/web', 0o755, true);
        symlink($this->root . '/client2/web8/web', "{$this->site}/web/blog");

        $this->expectExceptionMessage('is a link');
        Subfolder::webRoot($this->record('blog'), $this->guard());
    }

    public function testARecordWithAPathAsFolderIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        Subfolder::webRoot($this->record('../../client2/web8/web'), $this->guard());
    }

    public function testNestedSitesAreRealFoldersWithAConfigFile(): void
    {
        $this->wordpressIn('blog');
        $this->wordpressIn('Shop.old');
        mkdir("{$this->site}/web/images");
        mkdir("{$this->site}/web/linked");
        symlink("{$this->site}/web/blog/wp-config.php", "{$this->site}/web/linked/wp-config.php");
        symlink("{$this->site}/web/blog", "{$this->site}/web/alias");

        self::assertSame(['Shop.old', 'blog'], Subfolder::nested($this->record(), $this->guard()));
        self::assertSame([], Subfolder::nested($this->record('blog'), $this->guard()), 'one level only');
        self::assertSame(['--exclude=./blog'], Subfolder::excludes(['blog'], 'tar'));
        self::assertSame(['--exclude=/blog'], Subfolder::excludes(['blog', 'blog'], 'rsync'));
    }

    public function testBackupsOfTheSiteInWebLeaveTheNestedSitesOut(): void
    {
        $this->wordpressIn('blog');
        mkdir("{$this->site}/tmp/wpmanager-backups/20260101-000000", 0o700, true);
        foreach (['files.tar.gz', 'db.sql.gz', 'COMPLETE'] as $file) {
            touch("{$this->site}/tmp/wpmanager-backups/20260101-000000/{$file}");
        }
        $backups = new SiteBackup(Config::fromArray([]), $this->guard());
        $runner = new Runner(true);
        $backups->create($this->record(), $runner);
        $backups->restore($this->record(), '20260101-000000', $runner);
        $log = implode("\n", $runner->log());

        self::assertStringContainsString("'--exclude=./blog' '-C' '{$this->site}/web' '.'", $log);
        self::assertStringContainsString("'rsync' '-a' '--delete' '--exclude=/blog' '--'", $log);
    }

    public function testASiteInAFolderHasItsOwnBackupsAndDebugLog(): void
    {
        $backups = new SiteBackup(Config::fromArray([]), $this->guard());
        self::assertSame("{$this->site}/tmp/wpmanager-backups/subfolder-blog", $backups->directory($this->record('blog')));
        self::assertSame("{$this->site}/tmp/wpmanager-backups", $backups->directory($this->record()));
        self::assertSame('private/wp-debug-blog.log', WpDebug::log($this->record('blog')));
        self::assertSame(WpDebug::LOG, WpDebug::log($this->record()));
    }

    public function testThePruneOfTheSiteInWebKeepsTheBackupsOfTheFolders(): void
    {
        mkdir("{$this->site}/tmp/wpmanager-backups/subfolder-blog/20250101-000000", 0o700, true);
        $runner = new Runner(true);
        (new SiteBackup(Config::fromArray([]), $this->guard()))->prune($this->record(), $runner);

        self::assertSame([], $runner->log());
    }

    public function testWpCliRunsInTheFolderWithTheSandboxOfTheWholeWebsite(): void
    {
        mkdir("{$this->site}/web/blog");
        $command = WpCli::forSite(Config::fromArray([]), $this->guard(), $this->record('blog'))->coreVersion();
        $line = implode(' ', $command->args);

        self::assertStringContainsString("--path={$this->site}/web/blog", $line);
        self::assertStringContainsString("open_basedir={$this->site}:", $line);
        self::assertSame("{$this->site}/web/blog", $command->cwd);
    }

    public function testTheChecksOfASiteInAFolderAskItsOwnAddress(): void
    {
        $asked = [];
        $fetch = static function (string $url) use (&$asked): array {
            $asked[] = $url;
            return ['code' => 200, 'body' => ''];
        };
        (new HealthCheck($fetch, '127.0.0.1', 0))->status($this->record('blog') + ['site_url' => 'https://example.com/blog'], '/xmlrpc.php');

        self::assertSame(['https://example.com/blog/xmlrpc.php'], $asked);
    }

    public function testTheInstallCreatesTheFolderAndLeavesTheFilesOfTheWebsiteAlone(): void
    {
        $crypto = new Crypto(base64_decode(Crypto::generateKey()));
        touch("{$this->site}/web/index.html");
        $site = $this->record('blog') + [
            'site_url' => 'https://example.com/blog',
            'db_name' => 'c1_example_com_blog', 'db_user' => 'c1_example_com_blogu', 'db_host' => 'localhost',
            'wp_title' => 'Blog', 'wp_admin_user' => 'blogadmin', 'wp_admin_email' => 'a@example.com',
            'secret_db' => $crypto->encrypt('DbPw123'), 'secret_wp' => $crypto->encrypt('WpPw456'),
        ];
        $installer = new WordPressInstaller(Config::fromArray([]), $crypto, $this->guard());
        self::assertTrue($installer->isTargetEmpty($site));

        $log = implode("\n", $installer->install($site, new Runner(true, ['DbPw123', 'WpPw456'])));

        self::assertStringContainsString("'mkdir' '-m' '0755' '--' '{$this->site}/web/blog'", $log);
        self::assertStringContainsString("--path={$this->site}/web/blog", $log);
        self::assertStringContainsString("'--url=https://example.com/blog'", $log);
        self::assertStringNotContainsString('index.html', $log, 'the placeholder of ISPConfig belongs to the website');
    }

    public function testTheInstallRefusesAFolderWithAnythingInIt(): void
    {
        $crypto = new Crypto(base64_decode(Crypto::generateKey()));
        $installer = new WordPressInstaller(Config::fromArray([]), $crypto, $this->guard());
        mkdir("{$this->site}/web/blog");
        self::assertTrue($installer->isTargetEmpty($this->record('blog')));
        touch("{$this->site}/web/blog/.htaccess");
        self::assertFalse($installer->isTargetEmpty($this->record('blog')));
        unlink("{$this->site}/web/blog/.htaccess");
        rmdir("{$this->site}/web/blog");
        symlink($this->site . '/tmp', "{$this->site}/web/blog");
        self::assertFalse($installer->isTargetEmpty($this->record('blog')));

        $this->expectExceptionMessage('Refusing to install');
        $installer->install($this->record('blog') + ['secret_db' => '', 'secret_wp' => ''], new Runner(true));
    }

    public function testThePermissionCheckOfTheSiteInWebSkipsTheNestedSites(): void
    {
        $this->wordpressIn('blog');
        $runner = new Runner(true);
        (new SitePermissions(Config::fromArray([]), $this->guard(), new HealthCheck(static fn() => ['code' => 200, 'body' => ''], '127.0.0.1', 0)))
            ->check($this->record(), $runner);

        self::assertStringContainsString("'find' '{$this->site}/web' '-xdev' '(' '-path' '{$this->site}/web/blog' ')' '-prune' '-o' '!' '-type' 'l'", implode("\n", $runner->log()));
    }

    public function testTheScanFindsInstallsOneLevelBelowTheWebRoot(): void
    {
        $this->wordpressIn('blog');
        $this->wordpressIn('wp-admin');
        mkdir("{$this->site}/web/a/b", 0o755, true);
        touch("{$this->site}/web/a/b/wp-config.php");

        self::assertSame([[$this->site, 'blog']], (new SiteScanner($this->guard(), $this->root))->scanSubfolders());
        $record = SiteScanner::recordFor(
            ['domain' => 'example.com', 'domain_id' => 9, 'server_id' => 1, 'system_user' => 'web7', 'document_root' => $this->site,
                'ssl' => 'y', 'ssl_letsencrypt' => 'y', 'sys_userid' => 1, 'sys_groupid' => 2, 'sys_perm_user' => 'riud', 'sys_perm_group' => 'ru', 'sys_perm_other' => ''],
            3,
            '7.1.4',
            'blog',
        );
        self::assertSame('blog', $record['subfolder']);
        self::assertSame('https://example.com/blog', $record['site_url']);
        self::assertSame('detected', $record['origin']);
    }

    public function testRecordsOfTheSameWebsiteDifferByFolder(): void
    {
        $web = ['domain' => 'example.com', 'document_root' => $this->site, 'system_user' => 'web7', 'server_id' => 1];
        self::assertSame('', SiteRecord::mismatch($this->record('blog'), $web, 1, 1));
        self::assertStringContainsString('subfolder', SiteRecord::mismatch($this->record('../x'), $web, 1, 1));
        self::assertStringContainsString('same website and folder', SiteRecord::mismatch($this->record('blog'), $web, 1, 2));
    }

    public function testOnlyTheDatabaseAndItsUserAreCreatedForASiteInAFolder(): void
    {
        $crypto = new Crypto(base64_decode(Crypto::generateKey()));
        $t = new DryRunTransport();
        $provisioner = new SiteProvisioner(new IspconfigApi('https://panel.test/remote/json.php', 'u', 'p', $t), Config::fromArray([]), $crypto);
        $request = new SiteRequest('example.com', 3, 1, 'Blog', 'blogadmin', 'a@example.com', 'sftp', null, null, null, null, '', null, 'blog');
        $website = ['domain_id' => 9, 'type' => 'vhost', 'domain' => 'example.com', 'server_id' => 1, 'system_user' => 'web7',
            'document_root' => $this->site, 'ssl' => 'y', 'ssl_letsencrypt' => 'y'];

        $record = $provisioner->provisionInFolder($request, $website);

        self::assertSame(['sites_database_user_add', 'sites_database_add'], array_values(array_filter($t->received, static fn($m) => str_ends_with($m, '_add'))));
        self::assertNotContains('sites_shell_user_get', $t->received, 'no user of its own');
        self::assertSame('blog', $record['subfolder']);
        self::assertSame('https://example.com/blog', $record['site_url']);
        self::assertSame(9, $record['domain_id']);
        self::assertSame('c3_example_com_blog', $record['db_name']);
        self::assertArrayNotHasKey('secret_access', $record);
        self::assertNotSame(SiteProvisioner::folderBase('example.com', 'blog'), SiteProvisioner::folderBase('example.com', 'shop'));
        self::assertLessThanOrEqual(24, strlen(SiteProvisioner::folderBase('a-very-long-domain-name.example.com', 'a-long-folder-name')));
    }

    public function testHttpsOnlyWithACertificate(): void
    {
        self::assertSame('http', Subfolder::scheme(['ssl' => 'y', 'ssl_letsencrypt' => 'n', 'ssl_cert' => '']), 'Let\'s Encrypt failed: ISPConfig leaves ssl on');
        self::assertSame('https', Subfolder::scheme(['ssl' => 'y', 'ssl_letsencrypt' => 'y']));
        self::assertSame('https', Subfolder::scheme(['ssl' => 'y', 'ssl_letsencrypt' => 'n', 'ssl_cert' => "-----BEGIN CERTIFICATE-----\n"]));
        self::assertSame('http', Subfolder::scheme(['ssl' => 'n', 'ssl_letsencrypt' => 'y']));
    }

    public function testAWebsiteThatIsNotTheOneAskedForIsRefused(): void
    {
        $crypto = new Crypto(base64_decode(Crypto::generateKey()));
        $t = new DryRunTransport();
        $provisioner = new SiteProvisioner(new IspconfigApi('https://panel.test/remote/json.php', 'u', 'p', $t), Config::fromArray([]), $crypto);
        $request = new SiteRequest('example.com', 3, 1, 'Blog', 'blogadmin', 'a@example.com', 'sftp', null, null, null, null, '', null, 'blog');

        $this->expectException(ValidationException::class);
        $provisioner->provisionInFolder($request, ['domain_id' => 9, 'type' => 'vhost', 'domain' => 'other.com', 'server_id' => 1, 'system_user' => 'web7', 'document_root' => $this->site]);
    }

    public function testASiteInAFolderHasNoKeysOrQuotaOfItsOwn(): void
    {
        $this->expectException(ValidationException::class);
        new SiteRequest('example.com', 3, 1, 'Blog', 'blogadmin', 'a@example.com', 'sftp', null, null, null, null, '', 500, 'blog');
    }

    public function testTheWordPressPasswordAloneIsShownOnceAndExpires(): void
    {
        $crypto = new Crypto(base64_decode(Crypto::generateKey()));
        $site = ['status' => 'installed', 'wp_admin_user' => 'blogadmin', 'secret_wp' => $crypto->encrypt('WpPw456'), 'secret_access' => null,
            'access_type' => 'sftp', 'access_user' => ''];
        $credentials = new SiteCredentials($crypto);

        self::assertTrue($credentials->canReveal($site));
        self::assertSame('WpPw456', $credentials->reveal($site)['wp_password']);
        self::assertSame('', $credentials->reveal($site)['access_password']);
        self::assertSame(SiteCredentials::START_CLOCK, SiteCredentials::housekeeping($site + ['secret_since' => null], time()));
        self::assertTrue(SiteCredentials::hasExpired(['secret_wp' => null, 'secret_access' => null, 'secret_since' => '2026-01-01']));
    }

    public function testTheSiteInWebGoesOnlyAfterTheSitesInItsFolders(): void
    {
        $site = ['status' => 'installed', 'action_request' => '', 'domain_id' => 9, 'domain' => 'example.com'];
        $web = ['domain_id' => 9, 'domain' => 'example.com', 'type' => 'vhost'];

        self::assertStringContainsString('subfolders', SiteRemoval::refusal($site, $web, 0, 0, 1));
        self::assertSame('', SiteRemoval::refusal($site + ['subfolder' => 'blog'], $web, 0, 0, 1), 'a folder does not wait for its siblings');
        self::assertSame('', SiteRemoval::refusal($site, $web, 0, 0, 0));
    }

    public function testASiteInAFolderTakesOnlyItsDatabaseItsFreeUsersAndItsCronJob(): void
    {
        $site = $this->record('blog') + ['db_name' => 'c1_blog'];
        $databases = [
            ['database_id' => 1, 'database_name' => 'c1_main', 'database_user_id' => 10, 'database_ro_user_id' => 0],
            ['database_id' => 2, 'database_name' => 'c1_blog', 'database_user_id' => 11, 'database_ro_user_id' => 10],
        ];
        $users = [['database_user_id' => 11, 'database_user' => 'c1_blogu'], ['database_user_id' => 10, 'database_user' => 'c1_mainu']];

        $plan = SiteRemoval::folderPlan($site, $databases, $users, [10], ['id' => 4, 'command' => 'https://example.com/blog/wp-cron.php']);

        self::assertSame(
            [['cron', 4], ['web_database', 2], ['web_database_user', 11]],
            array_map(static fn(array $item): array => [$item['table'], $item['id']], $plan),
        );
        self::assertSame([2], array_column(SiteRemoval::ownDatabase($site, $databases), 'database_id'));
        self::assertSame(['c1_other'], SiteRemoval::notAttached(['db_name' => 'c1_other'], $databases));
    }

    public function testTheNodeDeletesTheFolderItsBackupsAndItsLogAsTheSiteUser(): void
    {
        $this->wordpressIn('blog');
        mkdir("{$this->site}/tmp/wpmanager-backups/subfolder-blog", 0o700, true);
        $commands = SiteRemoval::folderCommands($this->record('blog'), $this->guard(), new SiteBackup(Config::fromArray([]), $this->guard()));

        self::assertSame(
            [
                ['rm', '-rf', '--', "{$this->site}/web/blog"],
                ['rm', '-rf', '--', "{$this->site}/tmp/wpmanager-backups/subfolder-blog"],
                ['rm', '-f', '--', "{$this->site}/private/wp-debug-blog.log"],
            ],
            array_map(static fn($c): array => $c->args, $commands),
        );
        foreach ($commands as $command) {
            self::assertSame('web7', $command->runAs);
        }
    }

    public function testTheNodeNeverDeletesAWebsiteOrALinkedFolder(): void
    {
        $backups = new SiteBackup(Config::fromArray([]), $this->guard());
        try {
            SiteRemoval::folderCommands($this->record(), $this->guard(), $backups);
            self::fail('web/ itself accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Only a site in a subfolder', $e->getMessage());
        }
        symlink($this->site . '/tmp', "{$this->site}/web/blog");
        $this->expectExceptionMessage('is a link');
        SiteRemoval::folderCommands($this->record('blog'), $this->guard(), $backups);
    }

    public function testAFolderAlreadyGoneStillLosesItsBackupsAndLog(): void
    {
        $commands = SiteRemoval::folderCommands($this->record('blog'), $this->guard(), new SiteBackup(Config::fromArray([]), $this->guard()));

        self::assertSame([['rm', '-f', '--', "{$this->site}/private/wp-debug-blog.log"]], array_map(static fn($c): array => $c->args, $commands));
    }

    public function testASiteInAFolderIsNotClonedYet(): void
    {
        $crypto = new Crypto(base64_decode(Crypto::generateKey()));
        $cloner = new SiteCloner(Config::fromArray([]), $crypto, $this->guard());
        $source = $this->record('blog') + ['status' => 'installed', 'db_name' => 'a'];
        $target = ['wpm_site_id' => 6, 'server_id' => 1, 'db_name' => 'b', 'secret_db' => $crypto->encrypt('x'), 'document_root' => $this->site, 'system_user' => 'web7'];

        $this->expectExceptionMessage('cannot be cloned yet');
        $cloner->clone($target, $source, new Runner(true));
    }
}
