<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\SiteStaging;

final class SiteStagingTest extends TestCase
{
    public function testDefaultDomain(): void
    {
        self::assertSame('staging.example.com', SiteStaging::defaultDomain('example.com'));
        self::assertSame('staging.example.com', SiteStaging::defaultDomain('www.example.com'));
        self::assertSame('staging.shop.example.com', SiteStaging::defaultDomain(' Shop.Example.com '));
    }

    public function testAnInstalledSiteWithoutStagingCanHaveOne(): void
    {
        SiteStaging::assertCanCreate(['domain' => 'example.com', 'status' => 'installed', 'staging_of' => 0], null);
        $this->addToAssertionCount(1);
    }

    /** @dataProvider refused */
    public function testRefused(array $production, ?array $staging, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        SiteStaging::assertCanCreate($production + ['domain' => 'example.com', 'status' => 'installed', 'staging_of' => 0], $staging);
    }

    public static function refused(): array
    {
        return [
            'not installed' => [['status' => 'error'], null, 'not installed'],
            'a staging of a staging' => [['staging_of' => 4], null, 'is a staging site itself'],
            'one per site' => [[], ['domain' => 'staging.example.com'], 'already has a staging site: staging.example.com'],
        ];
    }

    // These live in ISPConfig, not in the files: a push must not be able to carry them to production.
    public function testWebsiteSettings(): void
    {
        self::assertSame('sendmail_path = /bin/true', SiteStaging::WEBSITE['custom_php_ini']);
        self::assertStringContainsString('X-Robots-Tag "noindex, nofollow"', SiteStaging::WEBSITE['apache_directives']);
    }

    public function testAPushListsWhatProductionWouldLose(): void
    {
        $production = [
            'version' => '7.1.5',
            'plugins' => [
                ['name' => 'akismet', 'version' => '5.8'],
                ['name' => 'woocommerce', 'version' => '10.1.0'],
                ['name' => 'hello', 'version' => '1.7.2'],
            ],
            'themes' => [['name' => 'twentytwentyfive', 'version' => '1.5']],
        ];
        $staging = [
            'version' => '7.1.4',
            'plugins' => [['name' => 'akismet', 'version' => '5.7.2'], ['name' => 'hello', 'version' => '1.7.2'], ['name' => 'new-on-staging', 'version' => '1.0']],
            'themes' => [['name' => 'twentytwentyfive', 'version' => '1.6']],
        ];

        self::assertSame([
            'WordPress 7.1.5 (staging 7.1.4)',
            'plugin akismet 5.8 (staging 5.7.2)',
            'plugin woocommerce 10.1.0 (not on the staging: it will be removed)',
        ], SiteStaging::lostOnPush($production, $staging));
    }

    public function testNothingLostWhenTheStagingIsAhead(): void
    {
        $site = ['version' => '7.1.4', 'plugins' => [['name' => 'akismet', 'version' => '5.7.2']], 'themes' => []];
        self::assertSame([], SiteStaging::lostOnPush($site, $site));
        // No report yet, or a failed check: nothing to compare, nothing invented.
        self::assertSame([], SiteStaging::lostOnPush([], []));
        self::assertSame([], SiteStaging::lostOnPush(['error' => 'timeout'], $site));
    }
}
