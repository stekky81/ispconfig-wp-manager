<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;

final class ConfigTest extends TestCase
{
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
