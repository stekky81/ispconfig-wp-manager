<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

/**
 * Where the pieces of a clone between two nodes wait: the master database, the only place both nodes reach.
 * States: requested (by the target node), sending (claimed by the source node), sent, failed.
 */
interface TransferStore
{
    /** A new request of the target node; returns its id. */
    public function open(int $sourceSiteId, int $targetSiteId, int $sourceServerId, int $targetServerId): int;

    /**
     * The oldest request for a source on this server, now marked as sending, or null.
     *
     * @return array{transfer_id:int, source_site_id:int, target_site_id:int}|null
     */
    public function claim(int $sourceServerId): ?array;

    /** @return array{state:string, error:string}|null */
    public function state(int $transferId): ?array;

    public function setState(int $transferId, string $state, string $error = ''): void;

    public function put(int $transferId, int $seq, string $data): void;

    /** How many pieces wait to be read. */
    public function pending(int $transferId): int;

    /** The piece, deleted from the store, or null when it is not there yet. */
    public function take(int $transferId, int $seq): ?string;

    /** The transfer and its pieces. */
    public function close(int $transferId): void;

    /** Transfers left behind by a node that stopped, older than this many seconds. */
    public function prune(int $olderThan): void;
}
