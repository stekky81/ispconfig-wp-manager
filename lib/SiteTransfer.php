<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * One end of a clone between two nodes, through the master database (see docs/architecture.md, Clone).
 *
 * The source node sends: a description of the site, the files as a tar stream, the database as a dump.
 * Each piece is encrypted with the shared key and starts with the transfer id, its number and its kind,
 * so a piece cannot be read on its way, moved to another transfer, reordered, repeated or dropped
 * without the target noticing. The end of each stream is a piece of its own: a cut stream is an error.
 */
final class SiteTransfer
{
    public const META = 1;
    public const FILES = 2;
    public const DATABASE = 3;

    /** Pieces waiting in the master database at most: about 32 MB. */
    public const MAX_PENDING = 16;
    public const PIECE_SIZE = 2097152;

    private int $seq = 0;
    /** @var callable(int): void */
    private $sleep;

    /** @param (callable(int): void)|null $sleep microseconds, replaced in tests */
    public function __construct(
        private readonly TransferStore $store,
        private readonly Crypto $crypto,
        private readonly int $transferId,
        private readonly int $patience = 600,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    // --- sending side

    /** @param array<string, string> $meta */
    public function sendMeta(array $meta): void
    {
        $this->send(self::META, json_encode($meta, JSON_THROW_ON_ERROR), true);
    }

    public function sendStream(Runner $runner, Command $command, int $kind): void
    {
        $runner->stream($command, function (string $piece) use ($kind): void {
            $this->send($kind, $piece, false);
        }, self::PIECE_SIZE);
        if (!$runner->isDryRun()) {
            $this->send($kind, '', true);
        }
    }

    public function finish(): void
    {
        $this->store->setState($this->transferId, 'sent');
    }

    public function fail(string $reason): void
    {
        $this->store->setState($this->transferId, 'failed', $reason);
    }

    private function send(int $kind, string $data, bool $last): void
    {
        $waited = 0;
        while ($this->store->pending($this->transferId) >= self::MAX_PENDING) {
            $this->assertAlive('The target node stopped the transfer');
            if ($waited++ > $this->patience * 2) {
                throw new \RuntimeException('The target node does not read the transfer');
            }
            ($this->sleep)(500_000);
        }
        $this->store->put($this->transferId, $this->seq, $this->crypto->seal(pack('NNCC', $this->transferId, $this->seq, $kind, $last ? 1 : 0) . $data));
        $this->seq++;
    }

    // --- receiving side

    /** @return array<string, string> */
    public function receiveMeta(): array
    {
        $meta = json_decode((string)$this->receive(self::META, true), true);
        if (!is_array($meta)) {
            throw new \RuntimeException('The description of the source site is not valid');
        }
        return array_map('strval', array_filter($meta, 'is_scalar'));
    }

    public function receiveStream(Runner $runner, Command $command, int $kind): void
    {
        $runner->feed($command, fn(): ?string => $this->receive($kind, false));
    }

    /** The data of the next piece; null at the end of a stream. */
    private function receive(int $kind, bool $single): ?string
    {
        $waited = 0;
        while (($sealed = $this->store->take($this->transferId, $this->seq)) === null) {
            $this->assertAlive('The source node failed');
            if ($waited++ > $this->patience * 2) {
                throw new \RuntimeException('The source node did not send the site: is its worker running?');
            }
            ($this->sleep)(500_000);
        }
        $plain = $this->crypto->open($sealed);
        if (strlen($plain) < 10) {
            throw new \RuntimeException('A piece of the transfer is too short');
        }
        $head = unpack('Nid/Nseq/Ckind/Clast', substr($plain, 0, 10));
        if ($head['id'] !== $this->transferId || $head['seq'] !== $this->seq || $head['kind'] !== $kind || ($single && $head['last'] !== 1)) {
            throw new \RuntimeException('A piece of the transfer is out of place');
        }
        $this->seq++;
        $data = substr($plain, 10);
        if ($single) {
            return $data;
        }
        if ($head['last'] === 1 && $data !== '') {
            throw new \RuntimeException('A piece of the transfer is out of place');
        }
        return $head['last'] === 1 ? null : $data;
    }

    private function assertAlive(string $what): void
    {
        $state = $this->store->state($this->transferId);
        if ($state === null) {
            throw new \RuntimeException("{$what}: the transfer is gone");
        }
        if ($state['state'] === 'failed') {
            throw new \RuntimeException("{$what}: " . ($state['error'] ?: 'no reason given'));
        }
    }
}
