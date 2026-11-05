<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\UpdateOverview;

final class UpdateOverviewTest extends TestCase
{
    private function row(string $server, int $count, array $report): array
    {
        return ['server_name' => $server, 'updates_count' => $count, 'updates_json' => (string)json_encode($report)];
    }

    public function testSummaryPerServer(): void
    {
        $summary = UpdateOverview::byServer([
            $this->row('node1', 2, ['core_update' => '8.0', 'plugins' => [['update' => '2']], 'themes' => []]),
            $this->row('node1', 0, ['core_update' => null, 'plugins' => [['update' => '']], 'themes' => []]),
            $this->row('node2', 1, ['core_update' => null, 'plugins' => [], 'themes' => [['update' => '3']]]),
        ]);

        self::assertSame([
            ['server_name' => 'node1', 'sites' => 2, 'with_updates' => 1, 'core' => 1, 'plugins' => 1, 'themes' => 0],
            ['server_name' => 'node2', 'sites' => 1, 'with_updates' => 1, 'core' => 0, 'plugins' => 0, 'themes' => 1],
        ], $summary);
    }

    public function testSiteNeverCheckedCountsAsASite(): void
    {
        $summary = UpdateOverview::byServer([['server_name' => 'node1', 'updates_count' => 0, 'updates_json' => null]]);

        self::assertSame(1, $summary[0]['sites']);
        self::assertSame(0, $summary[0]['with_updates']);
    }

    public function testAvailableKeepsOnlyWhatCanBeUpdated(): void
    {
        $json = (string)json_encode(['core_update' => '8.0', 'version' => '7.1.4', 'plugins' => [
            ['name' => 'a', 'version' => '1', 'update' => '2'],
            ['name' => 'b', 'version' => '1', 'update' => ''],
        ], 'themes' => [['name' => 'c', 'version' => '1', 'update' => '3']]]);

        self::assertSame([
            ['type' => 'core', 'name' => 'WordPress', 'version' => '7.1.4', 'update' => '8.0'],
            ['type' => 'plugin', 'name' => 'a', 'version' => '1', 'update' => '2'],
            ['type' => 'theme', 'name' => 'c', 'version' => '1', 'update' => '3'],
        ], UpdateOverview::available($json));
    }

    public function testStoredErrorAndBrokenJson(): void
    {
        self::assertSame('wp failed', UpdateOverview::error('{"error":"wp failed"}'));
        self::assertSame('', UpdateOverview::error('{"plugins":[]}'));
        self::assertSame('', UpdateOverview::error(null));
        self::assertSame([], UpdateOverview::available('not json'));
    }
}
