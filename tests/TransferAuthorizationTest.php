<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation\Tests;

use PHPUnit\Framework\TestCase;
use WPStation\TransferAuthorization;

final class TransferAuthorizationTest extends TestCase
{
    private static function datalog(array $new): string
    {
        // As the panel writes it: strings, with the old values empty for an insert.
        return serialize(['new' => array_map('strval', $new + ['domain' => 'copy.example.com']), 'old' => []]);
    }

    public function testAClonePanelAskedForIsSent(): void
    {
        self::assertSame('', TransferAuthorization::refusal(self::datalog(['wpstation_site_id' => 52, 'clone_of' => 43, 'server_id' => 2]), 43, 52, 2));
    }

    /** @return array<string, array{?string}> */
    public static function refused(): array
    {
        return [
            // A row that a node added itself has no record of the panel.
            'no record of the panel' => [null],
            'empty' => [''],
            'not serialized' => ['{"new":{"clone_of":43}}'],
            'another source' => [self::datalog(['wpstation_site_id' => 52, 'clone_of' => 44, 'server_id' => 2])],
            'another target' => [self::datalog(['wpstation_site_id' => 53, 'clone_of' => 43, 'server_id' => 2])],
            'another node' => [self::datalog(['wpstation_site_id' => 52, 'clone_of' => 43, 'server_id' => 3])],
            'not a clone' => [self::datalog(['wpstation_site_id' => 52, 'server_id' => 2])],
            'an object' => ['O:8:"stdClass":1:{s:3:"new";a:0:{}}'],
        ];
    }

    /** @dataProvider refused */
    public function testAnythingElseIsRefused(?string $datalog): void
    {
        self::assertNotSame('', TransferAuthorization::refusal($datalog, 43, 52, 2));
    }
}
