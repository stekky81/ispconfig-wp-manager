<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use WpManager\TransferStore;

/** The master database, in memory. */
final class MemoryTransferStore implements TransferStore
{
    /** @var array<int, array{state:string, error:string}> */
    public array $transfers = [];
    /** @var array<int, array<int, string>> */
    public array $pieces = [];

    public function open(int $sourceSiteId, int $targetSiteId, int $sourceServerId, int $targetServerId): int
    {
        $id = count($this->transfers) + 1;
        $this->transfers[$id] = ['state' => 'requested', 'error' => ''];
        return $id;
    }

    public function claim(int $sourceServerId): ?array
    {
        return null;
    }

    public function state(int $transferId): ?array
    {
        return $this->transfers[$transferId] ?? null;
    }

    public function setState(int $transferId, string $state, string $error = ''): void
    {
        $this->transfers[$transferId] = ['state' => $state, 'error' => $error];
    }

    public function put(int $transferId, int $seq, string $data): void
    {
        $this->pieces[$transferId][$seq] = $data;
    }

    public function pending(int $transferId): int
    {
        return count($this->pieces[$transferId] ?? []);
    }

    public function take(int $transferId, int $seq): ?string
    {
        $data = $this->pieces[$transferId][$seq] ?? null;
        unset($this->pieces[$transferId][$seq]);
        return $data;
    }

    public function close(int $transferId): void
    {
        unset($this->transfers[$transferId], $this->pieces[$transferId]);
    }

    public function prune(int $olderThan): void
    {
    }
}
