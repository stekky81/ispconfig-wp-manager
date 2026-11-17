<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation\Tests\Integration;

use WPStation\MaintenanceMode;
use WPStation\Runner;
use WPStation\SiteBackup;
use WPStation\SiteExtensions;
use WPStation\SiteHardening;
use WPStation\SiteInventory;
use WPStation\SitePermissions;
use WPStation\SiteUsers;
use WPStation\WpDebug;

/** The tools of the site page on a real WordPress, as the worker runs them. */
final class SiteToolsTest extends IntegrationTestCase
{
    public function testTheRunnerRunsAsTheSiteUserAndStopsAtItsTimeout(): void
    {
        self::assertSame('web901', trim(self::as(901, ['id', '-un'])['stdout']));

        $start = time();
        try {
            (new Runner(false, [], 2))->run(new \WPStation\Command(['sleep', '30'], 'web901', '/tmp'));
            self::fail('expected a timeout');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Timeout: ', $e->getMessage());
        }
        self::assertLessThan(15, time() - $start);
    }

    public function testMaintenanceModeLastsAndARestoreKeepsIt(): void
    {
        $site = self::site(901);
        $mode = new MaintenanceMode(self::config(), self::guard());
        $backups = new SiteBackup(self::config(), self::guard());
        $runner = new Runner(false, [], 600);

        $id = $backups->create($site, $runner);
        $mode->set($site, true, $runner);
        self::assertTrue($mode->isOn($site, $runner));

        // The backup was taken without it: the site keeps the mode it has now.
        $backups->restore($site, $id, $runner);
        self::assertTrue($mode->isOn($site, $runner));

        $mode->set($site, false, $runner);
        self::assertFalse($mode->isOn($site, $runner));
        self::assertFileDoesNotExist(self::web(901) . '/.maintenance');
    }

    public function testABackupBringsBackFilesAndDatabase(): void
    {
        $site = self::site(901);
        $backups = new SiteBackup(self::config(), self::guard());
        self::wp(901, 'option', 'update', 'blogname', 'Before');
        $id = $backups->create($site);

        self::wp(901, 'option', 'update', 'blogname', 'After');
        self::as(901, ['rm', '-f', '--', self::web(901) . '/wp-content/index.php']);
        $backups->restore($site, $id);

        self::assertSame('Before', self::wp(901, 'option', 'get', 'blogname'));
        self::assertFileExists(self::web(901) . '/wp-content/index.php');
    }

    public function testDebugModeLogsOutsideTheWebRootAndTurnsOff(): void
    {
        $site = self::site(901);
        $debug = new WpDebug(self::config(), self::guard());

        $debug->set($site, true);
        self::assertSame('1', self::wp(901, 'config', 'get', 'WP_DEBUG'));
        self::assertSame($site['document_root'] . '/private/wp-debug.log', self::wp(901, 'config', 'get', 'WP_DEBUG_LOG'));
        self::wp(901, 'eval', 'error_log("wpstation integration line");');
        self::assertStringContainsString('wpstation integration line', $debug->tail($site));

        $debug->set($site, false);
        self::assertSame('', self::wp(901, 'config', 'get', 'WP_DEBUG'));
        self::assertNotSame(0, self::as(901, [(string)getenv('WPSTATION_IT_WP_CLI'), 'config', 'has', 'WP_DEBUG_LOG', '--path=' . self::web(901)])['exit']);
    }

    public function testHardeningLeavesTheRulesOfWordPressAsTheyWere(): void
    {
        $site = self::site(901);
        $hardening = new SiteHardening(self::config(), self::guard(), self::health());
        $wordpress = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
        self::as(901, ['tee', '--', self::web(901) . '/.htaccess'], $wordpress);

        foreach (['sensitive_files', 'xmlrpc', 'uploads_php', 'file_edit'] as $measure) {
            self::assertSame('done', $hardening->set($site, $measure, true)['outcome'], $measure);
        }
        $state = $hardening->state($site, new Runner(false));
        self::assertSame(['file_edit' => true, 'uploads_php' => true, 'sensitive_files' => true, 'xmlrpc' => true], $state);
        self::assertStringEndsWith($wordpress, (string)file_get_contents(self::web(901) . '/.htaccess'));
        self::assertSame('web901', posix_getpwuid((int)fileowner(self::web(901) . '/wp-content/uploads/.htaccess'))['name']);

        foreach (['sensitive_files', 'xmlrpc', 'uploads_php', 'file_edit'] as $measure) {
            self::assertSame('done', $hardening->set($site, $measure, false)['outcome'], $measure);
        }
        self::assertSame($wordpress, file_get_contents(self::web(901) . '/.htaccess'));
        self::assertFileDoesNotExist(self::web(901) . '/wp-content/uploads/.htaccess');
        self::as(901, ['rm', '-f', '--', self::web(901) . '/.htaccess']);
    }

    public function testPermissionsAreFoundAndFixed(): void
    {
        $site = self::site(901);
        $permissions = new SitePermissions(self::config(), self::guard(), self::health());
        $open = self::web(901) . '/wpstation-open.txt';
        self::as(901, ['touch', '--', $open]);
        self::as(901, ['chmod', '666', '--', $open]);
        self::as(901, ['chmod', '644', '--', self::web(901) . '/wp-config.php']);

        $report = $permissions->check($site);
        self::assertGreaterThanOrEqual(1, $report['world_writable']);
        self::assertSame('644', $report['wp_config']);

        self::assertSame('done', $permissions->fix($site)['outcome']);
        clearstatcache();
        self::assertSame('664', substr(sprintf('%o', fileperms($open)), -3));
        self::assertSame('640', substr(sprintf('%o', fileperms(self::web(901) . '/wp-config.php')), -3));
        self::as(901, ['rm', '-f', '--', $open]);
    }

    public function testATemporaryLoginGoesAndLeavesItsPostsToAnAdministrator(): void
    {
        $site = self::site(901);
        $users = new SiteUsers(self::config(), self::guard());

        $login = $users->createLogin($site);
        $id = self::wp(901, 'user', 'get', $login['user'], '--field=ID');
        self::assertSame('administrator', self::wp(901, 'user', 'get', $login['user'], '--field=roles'));
        self::assertSame(0, self::as(901, [(string)getenv('WPSTATION_IT_WP_CLI'), 'user', 'check-password', $login['user'], $login['password'], '--path=' . self::web(901)])['exit']);
        $post = self::wp(901, 'post', 'create', '--post_title=wpstation', '--post_author=' . $id, '--porcelain');
        self::assertFalse($users->hasSession($site, $login['user']));

        $users->removeLogin($site, $login['user']);
        self::assertNotSame(0, self::as(901, [(string)getenv('WPSTATION_IT_WP_CLI'), 'user', 'get', $login['user'], '--path=' . self::web(901)])['exit']);
        self::assertSame('1', self::wp(901, 'post', 'get', $post, '--field=post_author'));
        self::wp(901, 'post', 'delete', $post, '--force');
    }

    public function testAPasswordResetGivesAPasswordThatWorks(): void
    {
        $reset = (new SiteUsers(self::config(), self::guard()))->resetPassword(self::site(901), 1);

        self::assertSame('itadmin', $reset['user']);
        self::assertSame(0, self::as(901, [(string)getenv('WPSTATION_IT_WP_CLI'), 'user', 'check-password', 'itadmin', $reset['password'], '--path=' . self::web(901)])['exit']);
    }

    public function testAPluginIsActivatedDeactivatedAndRemoved(): void
    {
        $site = self::site(901);
        $folder = self::web(901) . '/wp-content/plugins/wpstation-it-plugin';
        self::as(901, ['mkdir', '-p', '--', $folder]);
        self::as(901, ['tee', '--', $folder . '/wpstation-it-plugin.php'], "<?php\n/* Plugin Name: WPStation integration */\n");
        $extensions = new SiteExtensions(self::config(), self::guard(), new SiteBackup(self::config(), self::guard()), self::health());

        self::assertSame('done', $extensions->run($site, 'plugin_activate', 'wpstation-it-plugin')['outcome']);
        self::assertSame('active', self::wp(901, 'plugin', 'get', 'wpstation-it-plugin', '--field=status'));
        self::assertSame('done', $extensions->run($site, 'plugin_deactivate', 'wpstation-it-plugin')['outcome']);
        self::assertSame('inactive', self::wp(901, 'plugin', 'get', 'wpstation-it-plugin', '--field=status'));
        self::assertSame('done', $extensions->run($site, 'plugin_delete', 'wpstation-it-plugin')['outcome']);
        self::assertDirectoryDoesNotExist($folder);
        self::assertSame('refused', $extensions->run($site, 'plugin_delete', 'wpstation-it-plugin')['outcome']);
    }

    public function testTheDailyCheckReadsTheSite(): void
    {
        $report = (new SiteInventory(self::config(), self::guard()))->collect(self::site(901));

        self::assertMatchesRegularExpression('/^\d+\.\d+/', $report['version']);
        self::assertContains('itadmin', array_column($report['admins'], 'login'));
        self::assertNotEmpty($report['themes']);
        self::assertArrayHasKey('world_writable', $report['permissions']);
        self::assertArrayHasKey('xmlrpc', $report['hardening']);
    }
}
