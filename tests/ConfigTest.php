<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;

final class ConfigTest extends TestCase
{
    // Regression: root required a config.php owned by the panel user, so taking over the panel
    // meant running code as root within a minute.
    public function testRootOnlyLoadsAFileOwnedByTheExpectedUser(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wpm-cfg');
        file_put_contents($file, '<?php return [];');
        chmod($file, 0o640);

        self::assertTrue(Config::loadTrusted($file, (int)fileowner($file))->isDryRun());

        $this->expectExceptionMessage('must be owned by');
        try {
            Config::loadTrusted($file, (int)fileowner($file) + 1);
        } finally {
            unlink($file);
        }
    }

    public function testRootRefusesAConfigOthersCanWrite(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wpm-cfg');
        file_put_contents($file, '<?php return [];');
        chmod($file, 0o660);

        $this->expectExceptionMessage('must not be writable');
        try {
            Config::loadTrusted($file, (int)fileowner($file));
        } finally {
            unlink($file);
        }
    }

    public function testNoConfigFileMeansTheSafeDefaults(): void
    {
        self::assertTrue(Config::loadTrusted('/nonexistent/config.php')->isDryRun());
    }

    public function testDryRunIsOnByDefault(): void
    {
        self::assertTrue(Config::fromArray([])->isDryRun());
    }

    public function testOnlyExplicitFalseDisablesDryRun(): void
    {
        self::assertFalse(Config::fromArray(['dry_run' => false])->isDryRun());
        foreach ([0, '0', 'false', null, ''] as $value) {
            self::assertTrue(Config::fromArray(['dry_run' => $value])->isDryRun(), var_export($value, true));
        }
    }

    public function testNestedOverrideKeepsOtherDefaults(): void
    {
        $c = Config::fromArray(['site_defaults' => ['hd_quota' => 2048]]);
        self::assertSame(2048, $c->get('site_defaults.hd_quota'));
        self::assertSame('www', $c->get('site_defaults.subdomain'));
    }

    public function testListsAreReplacedNotMerged(): void
    {
        $c = Config::fromArray(['wordpress' => ['plugins' => ['a']]]);
        self::assertSame(['a'], $c->get('wordpress.plugins'));
    }

    public function testTyposInKeysAreRejected(): void
    {
        $this->expectExceptionMessage('Unknown config keys: dryrun');
        Config::fromArray(['dryrun' => false]);
    }

    public function testExampleConfigIsValid(): void
    {
        $c = Config::load(__DIR__ . '/../config/config.example.php');
        self::assertTrue($c->isDryRun(), 'The example config must ship with dry_run = true');
    }
}
