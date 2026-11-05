<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;
use WpManager\Crypto;
use WpManager\DryRunTransport;
use WpManager\IspconfigApi;
use WpManager\ProvisioningException;
use WpManager\SiteProvisioner;
use WpManager\SiteRequest;
use WpManager\ValidationException;

final class SiteProvisionerTest extends TestCase
{
    private Crypto $crypto;

    protected function setUp(): void
    {
        $this->crypto = new Crypto(base64_decode(Crypto::generateKey()));
    }

    private function provisioner(DryRunTransport $t): SiteProvisioner
    {
        $api = new IspconfigApi('https://panel.test/remote/json.php', 'u', 'p', $t);
        return new SiteProvisioner($api, Config::fromArray([]), $this->crypto);
    }

    private function request(string $domain = 'shop.example.com'): SiteRequest
    {
        return new SiteRequest($domain, 3, 1, 'My shop', 'stefano', 'stefano@example.com');
    }

    public function testHappyPathCreatesObjectsInOrder(): void
    {
        $t = new DryRunTransport();
        $record = $this->provisioner($t)->provision($this->request());

        $adds = array_values(array_filter($t->received, fn($m) => str_ends_with($m, '_add')));
        self::assertSame(
            ['sites_web_domain_add', 'sites_database_user_add', 'sites_database_add', 'sites_shell_user_add'],
            $adds,
        );
        self::assertSame('queued', $record['status']);
        self::assertSame('c3_shop_example_com', $record['db_name']);
        self::assertMatchesRegularExpression('/^web\d+$/', $record['system_user']);
    }

    public function testSecretsInRecordAreEncrypted(): void
    {
        $record = $this->provisioner(new DryRunTransport())->provision($this->request());
        foreach (['secret_db', 'secret_wp', 'secret_access'] as $field) {
            self::assertStringStartsWith('wpm1:', $record[$field]);
            self::assertGreaterThanOrEqual(24, strlen($this->crypto->decrypt($record[$field])));
        }
    }

    public function testExistingDomainStopsBeforeCreatingAnything(): void
    {
        $t = new DryRunTransport(existingDomains: ['shop.example.com']);
        try {
            $this->provisioner($t)->provision($this->request());
            self::fail('Duplicate domain accepted');
        } catch (ValidationException) {
            self::assertEmpty(array_filter($t->received, fn($m) => str_ends_with($m, '_add')));
        }
    }

    public function testFailureHalfwayRollsBackInReverseOrder(): void
    {
        $t = new DryRunTransport(failOn: 'sites_database_add');
        try {
            $this->provisioner($t)->provision($this->request());
            self::fail('Failure not reported');
        } catch (ProvisioningException $e) {
            self::assertSame([], $e->leftovers);
            $deletes = array_values(array_filter($t->received, fn($m) => str_ends_with($m, '_delete')));
            self::assertSame(['sites_database_user_delete', 'sites_web_domain_delete'], $deletes);
        }
    }

    public function testFailedRollbackIsReportedNotHidden(): void
    {
        $t = new DryRunTransport(failOn: 'sites_shell_user_add', failDeleteOn: 'sites_database_delete');
        try {
            $this->provisioner($t)->provision($this->request());
            self::fail('Failure not reported');
        } catch (ProvisioningException $e) {
            self::assertCount(1, $e->leftovers);
            self::assertStringContainsString('remove manually', $e->getMessage());
        }
    }

    public function testApiCallLogNeverContainsPasswords(): void
    {
        $t = new DryRunTransport();
        $api = new IspconfigApi('https://panel.test/remote/json.php', 'u', 'TopSecretRemote', $t);
        $record = (new SiteProvisioner($api, Config::fromArray([]), $this->crypto))->provision($this->request());
        $log = json_encode($api->calls());
        self::assertStringNotContainsString('TopSecretRemote', $log);
        self::assertStringNotContainsString($this->crypto->decrypt($record['secret_db']), $log);
        self::assertStringNotContainsString('dry-run-session', $log);
    }

    public function testDefaultAccessIsAnSftpShellUser(): void
    {
        $t = new DryRunTransport();
        $record = $this->provisioner($t)->provision($this->request());

        self::assertContains('sites_shell_user_add', $t->received);
        self::assertNotContains('sites_ftp_user_add', $t->received);
        self::assertSame('sftp', $record['access_type']);
    }

    public function testFtpAccessCreatesAnFtpUserInsteadOfAShellUser(): void
    {
        $t = new DryRunTransport();
        $req = new SiteRequest('shop.example.com', 3, 1, 'My shop', 'stefano', 'stefano@example.com', 'ftp');
        $record = $this->provisioner($t)->provision($req);

        self::assertContains('sites_ftp_user_add', $t->received);
        self::assertNotContains('sites_shell_user_add', $t->received);
        self::assertSame('ftp', $record['access_type']);
        self::assertSame('c3_shop_example_comf', $record['access_user']);
    }

    public function testFailedFtpUserRollsBackTheOtherObjects(): void
    {
        $t = new DryRunTransport(failOn: 'sites_ftp_user_add');
        $req = new SiteRequest('shop.example.com', 3, 1, 'My shop', 'stefano', 'stefano@example.com', 'ftp');

        try {
            $this->provisioner($t)->provision($req);
            self::fail('expected a ProvisioningException');
        } catch (ProvisioningException) {
            self::assertContains('sites_database_delete', $t->received);
            self::assertContains('sites_web_domain_delete', $t->received);
        }
    }

    public function testUnknownAccessTypeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        new SiteRequest('shop.example.com', 3, 1, 'My shop', 'stefano', 'stefano@example.com', 'telnet');
    }

    public function testOptionsFallBackToTheDefaults(): void
    {
        $api = new IspconfigApi('https://panel.test/remote/json.php', 'u', 'p', new DryRunTransport());
        $config = Config::fromArray(['wordpress' => ['locale' => 'de_DE', 'plugins' => ['akismet'], 'theme' => 'astra']]);
        $record = (new SiteProvisioner($api, $config, $this->crypto))->provision($this->request());

        self::assertSame('de_DE', $record['wp_locale']);
        self::assertSame('akismet', $record['wp_plugins']);
        self::assertSame('astra', $record['wp_theme']);
    }

    public function testOptionsOfTheRequestWinOverTheDefaults(): void
    {
        $api = new IspconfigApi('https://panel.test/remote/json.php', 'u', 'p', new DryRunTransport());
        $config = Config::fromArray(['wordpress' => ['plugins' => ['akismet'], 'theme' => 'astra']]);
        $req = new SiteRequest('shop.example.com', 3, 1, 'My shop', 'stefano', 'stefano@example.com', 'sftp', 'en_GB', '', '', '4');
        $record = (new SiteProvisioner($api, $config, $this->crypto))->provision($req);

        self::assertSame('en_GB', $record['wp_locale']);
        self::assertSame('', $record['wp_plugins']);
        self::assertSame('', $record['wp_theme']);

        $add = array_values(array_filter($api->calls(), fn($c) => $c['method'] === 'sites_web_domain_add'))[0];
        self::assertSame(4, $add['params']['params']['server_php_id']);
    }

    /** @dataProvider unsafeOptions */
    public function testUnsafeOptionsAreRejected(?string $locale, ?string $plugins, ?string $theme, mixed $phpId): void
    {
        $this->expectException(ValidationException::class);
        new SiteRequest('shop.example.com', 3, 1, 'My shop', 'stefano', 'stefano@example.com', 'sftp', $locale, $plugins, $theme, $phpId);
    }

    public static function unsafeOptions(): array
    {
        return [
            'locale' => ['it_IT; rm -rf /', null, null, null],
            'plugin' => [null, 'akismet,--require=/tmp/x.php', null, null],
            'theme' => [null, null, '../../evil', null],
            'php id' => [null, null, null, '-1'],
        ];
    }

    public function testPluginListAcceptsCommasAndSpaces(): void
    {
        $req = new SiteRequest('shop.example.com', 3, 1, 'My shop', 'stefano', 'stefano@example.com', 'sftp', null, ' akismet, wordfence  jetpack ');

        self::assertSame(['akismet', 'wordfence', 'jetpack'], $req->plugins);
    }
}
