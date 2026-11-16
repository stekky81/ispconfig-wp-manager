<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\SiteRecord;

final class SiteRecordTest extends TestCase
{
    private const SITE = [
        'domain_id' => 12, 'server_id' => 2, 'domain' => 'shop.example.com',
        'document_root' => '/var/www/clients/client1/web12', 'system_user' => 'web12',
    ];
    private const WEB = [
        'domain_id' => 12, 'server_id' => 2, 'domain' => 'Shop.example.com',
        'document_root' => '/var/www/clients/client1/web12/', 'system_user' => 'web12',
    ];

    public function testARowThatMatchesItsWebsiteIsTrusted(): void
    {
        self::assertSame('', SiteRecord::mismatch(self::SITE, self::WEB, 2));
    }

    /** @return array<string, array{array, ?array, int, int, string}> */
    public static function untrusted(): array
    {
        return [
            'no website' => [self::SITE, null, 2, 1, 'not linked'],
            'no link' => [['domain_id' => 0] + self::SITE, self::WEB, 2, 1, 'not linked'],
            'row of another server' => [['server_id' => 3] + self::SITE, self::WEB, 2, 1, 'another server'],
            'website of another server' => [self::SITE, ['server_id' => 3] + self::WEB, 2, 1, 'another server'],
            // What a node that was broken into would change to reach another folder.
            'other folder' => [['document_root' => '/var/www/clients/client2/web30'] + self::SITE, self::WEB, 2, 1, 'document_root'],
            'other user' => [['system_user' => 'web30'] + self::SITE, self::WEB, 2, 1, 'system_user'],
            'other domain' => [['domain' => 'evil.example.com'] + self::SITE, self::WEB, 2, 1, 'domain'],
            // A row added for a website that is already in the list, for example with automatic updates on.
            'second row' => [self::SITE, self::WEB, 2, 2, 'more than one'],
        ];
    }

    /** @dataProvider untrusted */
    public function testARowThatDoesNotMatchIsRefused(array $site, ?array $web, int $server, int $records, string $reason): void
    {
        self::assertStringContainsString($reason, SiteRecord::mismatch($site, $web, $server, $records));
    }
}
