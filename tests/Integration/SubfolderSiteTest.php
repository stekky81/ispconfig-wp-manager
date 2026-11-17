<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation\Tests\Integration;

use WPStation\Crypto;
use WPStation\MaintenanceMode;
use WPStation\Runner;
use WPStation\SiteBackup;
use WPStation\SitePermissions;
use WPStation\SiteRemoval;
use WPStation\WordPressInstaller;

/**
 * A second WordPress in web901/web/blog, next to the one in web901/web: what one does must never reach the other.
 */
final class SubfolderSiteTest extends IntegrationTestCase
{
    private static Crypto $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        self::$crypto ??= new Crypto(sodium_crypto_secretbox_keygen());
    }

    private static function blog(): array
    {
        return [
            'subfolder' => 'blog',
            'site_url' => 'http://it901.test/blog',
            'db_name' => 'wpsit_blog901',
            'db_user' => 'wpsit_blog901',
            'wp_title' => 'Blog',
            'wp_admin_user' => 'blogadmin',
            'wp_admin_email' => 'blog@example.com',
            'wp_locale' => 'en_US',
            'wp_plugins' => '',
            'wp_theme' => '',
            'secret_db' => self::$crypto->encrypt((string)getenv('WPSTATION_IT_DB_PASSWORD_BLOG')),
            'secret_wp' => self::$crypto->encrypt('BlogPw-' . bin2hex(random_bytes(6))),
        ] + self::site(901);
    }

    private static function blogWp(string ...$args): string
    {
        return trim(self::as(901, array_merge([(string)getenv('WPSTATION_IT_WP_CLI')], $args, ['--path=' . self::web(901) . '/blog', '--skip-plugins', '--skip-themes']))['stdout']);
    }

    /** A fresh install in the folder, as the worker makes it. */
    private static function install(): void
    {
        exec('rm -rf -- ' . escapeshellarg(self::web(901) . '/blog'));
        // An empty database again, with the account of the blog, which may drop and create its own.
        $mysql = proc_open(
            ['mysql', '--no-defaults', '-h', (string)getenv('WPSTATION_IT_DB_HOST'), '-u', 'wpsit_blog901', '-e',
                'DROP DATABASE IF EXISTS wpsit_blog901; CREATE DATABASE wpsit_blog901 CHARACTER SET utf8mb4'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['MYSQL_PWD' => (string)getenv('WPSTATION_IT_DB_PASSWORD_BLOG'), 'PATH' => (string)getenv('PATH')],
        );
        $error = stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($mysql), (string)$error);
        $installer = new WordPressInstaller(self::config(), self::$crypto, self::guard());
        self::assertTrue($installer->isTargetEmpty(self::blog()));
        $installer->install(self::blog(), new Runner(false, [], 600));
    }

    public function testTheBlogLivesInItsFolderWithItsOwnAddressAndDatabase(): void
    {
        self::install();

        self::assertSame('http://it901.test/blog', self::blogWp('option', 'get', 'home'));
        self::assertSame('wpsit_blog901', self::blogWp('config', 'get', 'DB_NAME'));
        self::assertSame('http://it901.test', self::wp(901, 'option', 'get', 'home'), 'the site in web/ stays as it was');
        self::assertSame('web901', posix_getpwuid((int)fileowner(self::web(901) . '/blog'))['name']);
        self::assertFalse((new WordPressInstaller(self::config(), self::$crypto, self::guard()))->isTargetEmpty(self::blog()));
    }

    public function testBackupAndRestoreOfTheSiteInWebLeaveTheBlogAlone(): void
    {
        self::install();
        $backups = new SiteBackup(self::config(), self::guard());
        $id = $backups->create(self::site(901));

        $listing = self::as(901, ['tar', '-tzf', $backups->directory(self::site(901)) . "/{$id}/files.tar.gz"])['stdout'];
        self::assertStringNotContainsString('./blog', $listing);

        self::as(901, ['tee', '--', self::web(901) . '/blog/after-the-backup.txt'], 'kept');
        $backups->restore(self::site(901), $id);

        self::assertFileExists(self::web(901) . '/blog/after-the-backup.txt', 'the restore of web/ did not touch web/blog');
        self::assertFileExists(self::web(901) . '/blog/wp-config.php');
    }

    public function testTheBlogHasItsOwnBackupsAndTools(): void
    {
        self::install();
        $blog = self::blog();
        $backups = new SiteBackup(self::config(), self::guard());
        $parentBackups = count($backups->list(self::site(901)));

        self::blogWp('option', 'update', 'blogname', 'Before');
        $id = $backups->create($blog);
        self::assertStringEndsWith('/wpstation-backups/subfolder-blog', $backups->directory($blog));
        self::blogWp('option', 'update', 'blogname', 'After');
        $backups->restore($blog, $id);
        self::assertSame('Before', self::blogWp('option', 'get', 'blogname'));
        self::assertCount($parentBackups, $backups->list(self::site(901)), 'the backups of web/ are apart');

        $mode = new MaintenanceMode(self::config(), self::guard());
        $mode->set($blog, true);
        self::assertFileExists(self::web(901) . '/blog/.maintenance');
        self::assertFalse($mode->isOn(self::site(901), new Runner(false)));
        $mode->set($blog, false);

        // A file anyone can write in the blog is the blog's business, not the site's in web/.
        self::as(901, ['touch', '--', self::web(901) . '/blog/open.txt']);
        self::as(901, ['chmod', '666', '--', self::web(901) . '/blog/open.txt']);
        $permissions = new SitePermissions(self::config(), self::guard(), self::health());
        self::assertSame(0, $permissions->check(self::site(901))['world_writable']);
        self::assertGreaterThanOrEqual(1, $permissions->check($blog)['world_writable']);
    }

    public function testRemovingTheBlogLeavesTheSiteInWeb(): void
    {
        self::install();
        $blog = self::blog();
        $backups = new SiteBackup(self::config(), self::guard());
        $backups->create($blog);

        $runner = new Runner(false, [], 600);
        foreach (SiteRemoval::folderCommands($blog, self::guard(), $backups) as $command) {
            $runner->mustRun($command);
        }
        clearstatcache();

        self::assertDirectoryDoesNotExist(self::web(901) . '/blog');
        self::assertDirectoryDoesNotExist($backups->directory($blog));
        self::assertFileExists(self::web(901) . '/wp-config.php');
        self::assertSame('http://it901.test', self::wp(901, 'option', 'get', 'home'));
    }
}
