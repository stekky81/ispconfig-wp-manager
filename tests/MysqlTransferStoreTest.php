<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation\Tests;

use PHPUnit\Framework\TestCase;
use WPStation\MysqlTransferStore;

/**
 * Needs a MariaDB database with the tables of install/sql/wpstation.sql: set WPSTATION_TEST_DB_HOST, _USER, _PASSWORD
 * and _NAME. Skipped otherwise (run it in the test VM).
 */
final class MysqlTransferStoreTest extends TestCase
{
    private MysqlTransferStore $store;

    protected function setUp(): void
    {
        $host = getenv('WPSTATION_TEST_DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('No test database (WPSTATION_TEST_DB_HOST)');
        }
        $this->store = new MysqlTransferStore($host, (string)getenv('WPSTATION_TEST_DB_USER'), (string)getenv('WPSTATION_TEST_DB_PASSWORD'), (string)getenv('WPSTATION_TEST_DB_NAME'));
    }

    // Regression (VM): the claim was counted on the connection, which says 0 for a prepared statement, so the
    // source node marked the transfer as its own and then never sent it.
    public function testAClaimedTransferIsReturnedOnceToOneNode(): void
    {
        $id = $this->store->open(7, 9, 990001, 2);
        try {
            $claim = $this->store->claim(990001);
            self::assertSame(['transfer_id' => $id, 'source_site_id' => 7, 'target_site_id' => 9], $claim);
            self::assertSame('sending', $this->store->state($id)['state']);
            self::assertNull($this->store->claim(990001), 'already claimed');
        } finally {
            $this->store->close($id);
        }
    }

    public function testPiecesKeepEveryByte(): void
    {
        $id = $this->store->open(7, 9, 990002, 2);
        try {
            $data = random_bytes(3 * 1024 * 1024) . "\0'\"\\";
            $this->store->put($id, 0, $data);
            self::assertSame(1, $this->store->pending($id));
            self::assertNull($this->store->take($id, 1));
            self::assertSame($data, $this->store->take($id, 0));
            self::assertSame(0, $this->store->pending($id));
            $this->store->setState($id, 'failed', 'disk full');
            self::assertSame(['state' => 'failed', 'error' => 'disk full'], $this->store->state($id));
        } finally {
            $this->store->close($id);
        }
        self::assertNull($this->store->state($id));
    }
}
