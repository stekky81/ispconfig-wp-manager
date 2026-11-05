<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;
use WpManager\Crypto;
use WpManager\JobQueue;
use WpManager\PathGuard;
use WpManager\Runner;
use WpManager\WordPressInstaller;

final class WordPressInstallerTest extends TestCase
{
    private string $root;
    private Crypto $crypto;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wpm-inst-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/client3/web7/web', 0o755, true);
        touch($this->root . '/client3/web7/web/index.html');
        $this->crypto = new Crypto(base64_decode(Crypto::generateKey()));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function site(): array
    {
        return [
            'domain' => 'shop.example.com',
            'document_root' => $this->root . '/client3/web7',
            'system_user' => 'web7',
            'db_name' => 'c3_shop',
            'db_user' => 'c3_shopu',
            'db_host' => 'localhost',
            'wp_title' => 'Shop',
            'wp_admin_user' => 'stefano',
            'wp_admin_email' => 'stefano@example.com',
            'secret_db' => $this->crypto->encrypt('DbPw123'),
            'secret_wp' => $this->crypto->encrypt('WpPw456'),
        ];
    }

    private function installer(array $config = []): WordPressInstaller
    {
        return new WordPressInstaller(Config::fromArray($config), $this->crypto, new PathGuard($this->root));
    }

    public function testDryRunPlansFullInstallWithoutTouchingFiles(): void
    {
        $runner = new Runner(true, ['DbPw123', 'WpPw456']);
        $log = $this->installer(['wordpress' => ['plugins' => ['wordfence']]])->install($this->site(), $runner);
        $all = implode("\n", $log);

        self::assertStringContainsString("'core' 'download'", $all);
        self::assertStringContainsString("'config' 'create'", $all);
        self::assertStringContainsString("'core' 'install'", $all);
        self::assertStringContainsString("'wordfence'", $all);
        self::assertStringContainsString("'chmod' '0640'", $all);
        self::assertStringNotContainsString('DbPw123', $all);
        self::assertStringNotContainsString('WpPw456', $all);
        self::assertFileExists($this->root . '/client3/web7/web/index.html', 'dry-run must not delete files');
    }

    public function testPlaceholderPagesAreRemovedBeforeTheInstall(): void
    {
        touch($this->root . '/client3/web7/web/standard_index.html');
        $all = implode("\n", $this->installer()->install($this->site(), new Runner(true)));

        self::assertStringContainsString("/web/index.html'", $all);
        self::assertStringContainsString("/web/standard_index.html'", $all);
        self::assertLessThan(strpos($all, "'core' 'download'"), strpos($all, 'standard_index.html'));
    }

    // Regression: without a certificate the site was installed with an https URL that nothing
    // answered, so wp-login redirected to a dead port.
    public function testSiteUrlUsesHttpWithoutACertificate(): void
    {
        $log = $this->installer(['site_defaults' => ['ssl' => 'y', 'ssl_letsencrypt' => 'n']])
            ->install($this->site(), new Runner(true));

        self::assertStringContainsString("'--url=http://shop.example.com'", implode("\n", $log));
    }

    public function testSiteUrlUsesHttpsWithALetsEncryptCertificate(): void
    {
        $log = $this->installer(['site_defaults' => ['ssl' => 'y', 'ssl_letsencrypt' => 'y']])
            ->install($this->site(), new Runner(true));

        self::assertStringContainsString("'--url=https://shop.example.com'", implode("\n", $log));
    }

    public function testSiteUrlUsesHttpWhenSslIsOff(): void
    {
        $log = $this->installer(['site_defaults' => ['ssl' => 'n', 'ssl_letsencrypt' => 'y']])
            ->install($this->site(), new Runner(true));

        self::assertStringContainsString("'--url=http://shop.example.com'", implode("\n", $log));
    }

    // Regression: the worker never stored the version, so the list column stayed empty.
    public function testVersionOutputIsParsed(): void
    {
        self::assertSame('7.1.4', WordPressInstaller::parseVersion("7.1.4\n"));
        self::assertSame('7.1', WordPressInstaller::parseVersion('7.1'));
        self::assertSame('7.2-beta1', WordPressInstaller::parseVersion('7.2-beta1'));
    }

    public function testUnexpectedVersionOutputIsIgnored(): void
    {
        self::assertSame('', WordPressInstaller::parseVersion(''));
        self::assertSame('', WordPressInstaller::parseVersion('Error: this does not seem to be a WordPress install'));
    }

    public function testDryRunHasNoVersion(): void
    {
        self::assertSame('', $this->installer()->installedVersion($this->site(), new Runner(true)));
    }

    // The panel decides the URL: settings changed there do not reach the configuration of a node.
    public function testSiteUrlOfTheRecordWinsOverTheConfiguration(): void
    {
        $site = $this->site() + ['site_url' => 'http://shop.example.com'];
        $log = $this->installer(['site_defaults' => ['ssl' => 'y', 'ssl_letsencrypt' => 'y']])->install($site, new Runner(true));

        self::assertStringContainsString("'--url=http://shop.example.com'", implode("\n", $log));
    }

    public function testRecordOptionsWinOverTheConfiguration(): void
    {
        $site = $this->site() + ['wp_locale' => 'en_GB', 'wp_plugins' => 'akismet,jetpack', 'wp_theme' => 'astra'];
        $log = implode("\n", $this->installer(['wordpress' => ['plugins' => ['wordfence']]])->install($site, new Runner(true)));

        self::assertStringContainsString("'--locale=en_GB'", $log);
        self::assertStringContainsString("'akismet'", $log);
        self::assertStringContainsString("'jetpack'", $log);
        self::assertStringContainsString("'theme' 'install' 'astra'", $log);
        self::assertStringNotContainsString('wordfence', $log);
    }

    public function testEmptyRecordOptionsInstallNoPluginOrTheme(): void
    {
        $site = $this->site() + ['wp_locale' => 'it_IT', 'wp_plugins' => '', 'wp_theme' => ''];
        $log = implode("\n", $this->installer(['wordpress' => ['plugins' => ['wordfence'], 'theme' => 'astra']])->install($site, new Runner(true)));

        self::assertStringNotContainsString("'plugin' 'install'", $log);
        self::assertStringNotContainsString("'theme' 'install'", $log);
    }

    public function testRefusesNonEmptyWebRoot(): void
    {
        file_put_contents($this->root . '/client3/web7/web/index.php', '<?php // existing site');
        $this->expectExceptionMessage('Refusing to install');
        $this->installer()->install($this->site(), new Runner(true));
    }

    public function testRefusesDocumentRootOutsideClients(): void
    {
        $site = $this->site();
        $site['document_root'] = '/etc';
        $this->expectException(\RuntimeException::class);
        $this->installer()->install($site, new Runner(true));
    }

    public function testNotReadyUntilIspconfigCreatedTheVhost(): void
    {
        $site = $this->site();
        self::assertTrue($this->installer()->isReady($site));
        $site['document_root'] = $this->root . '/client3/web99';
        self::assertFalse($this->installer()->isReady($site));
    }

    public function testJobQueueRoundTrip(): void
    {
        $q = new JobQueue($this->root . '/spool');
        $name = $q->push('install', 42, ['domain' => 'a.example.com']);
        self::assertSame(['install-42.json'], $q->pending());
        self::assertSame(0o600, fileperms($this->root . '/spool/' . $name) & 0o777);
        $job = $q->read($name);
        $job['attempts'] = 2;
        $q->update($name, $job);
        self::assertSame(2, $q->read($name)['attempts']);
        $q->remove($name);
        self::assertSame([], $q->pending());
        $this->expectException(\InvalidArgumentException::class);
        $q->read('../../etc/passwd');
    }
}
