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
        foreach (['secret_db', 'secret_wp', 'secret_sftp'] as $field) {
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
}
