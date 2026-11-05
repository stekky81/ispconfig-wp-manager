<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\PathGuard;
use WpManager\SiteScanner;

final class SiteScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wpm-scan-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->root = (string)realpath($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function site(string $path, bool $wordpress = true): string
    {
        mkdir("{$this->root}/{$path}/web", 0o755, true);
        if ($wordpress) {
            touch("{$this->root}/{$path}/web/wp-config.php");
        }
        return "{$this->root}/{$path}";
    }

    private function scan(): array
    {
        return (new SiteScanner(new PathGuard($this->root), $this->root))->scan();
    }

    public function testFindsSitesWithAConfigFile(): void
    {
        $one = $this->site('client1/web1');
        $two = $this->site('client2/web7');
        $this->site('client2/web8', false);

        self::assertSame([$one, $two], $this->scan());
    }

    public function testIgnoresConfigFilesOutsideTheWebRoot(): void
    {
        $this->site('client1/web1', false);
        mkdir("{$this->root}/client1/web1/private");
        touch("{$this->root}/client1/web1/private/wp-config.php");
        mkdir("{$this->root}/client1/web1/web/old");
        touch("{$this->root}/client1/web1/web/old/wp-config.php");

        self::assertSame([], $this->scan());
    }

    public function testIgnoresASymlinkedConfigFile(): void
    {
        $this->site('client1/web1', false);
        symlink('/etc/passwd', "{$this->root}/client1/web1/web/wp-config.php");

        self::assertSame([], $this->scan());
    }

    public function testIgnoresASymlinkedWebRoot(): void
    {
        $target = $this->site('client1/web1');
        mkdir("{$this->root}/client1/web2");
        symlink("{$target}/web", "{$this->root}/client1/web2/web");

        self::assertSame([$target], $this->scan());
    }

    public function testRecordOfADetectedSiteHasNoSecrets(): void
    {
        $record = SiteScanner::recordFor([
            'domain_id' => 5,
            'domain' => 'shop.example.com',
            'server_id' => 1,
            'system_user' => 'web5',
            'document_root' => '/var/www/clients/client1/web5',
            'sys_userid' => 1,
            'sys_groupid' => 2,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'ru',
            'sys_perm_other' => '',
        ], 3, '7.1.4');

        self::assertSame('detected', $record['origin']);
        self::assertSame('installed', $record['status']);
        self::assertSame('7.1.4', $record['wp_version']);
        self::assertSame(3, $record['client_id']);
        self::assertSame(5, $record['domain_id']);
        foreach (['secret_db', 'secret_wp', 'secret_access'] as $field) {
            self::assertArrayNotHasKey($field, $record);
        }
    }
}
