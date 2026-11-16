<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Command;
use WpManager\Crypto;
use WpManager\Runner;
use WpManager\SiteTransfer;

final class SiteTransferTest extends TestCase
{
    private MemoryTransferStore $store;
    private Crypto $crypto;
    private string $out;

    protected function setUp(): void
    {
        $this->store = new MemoryTransferStore();
        $this->crypto = new Crypto(base64_decode(Crypto::generateKey()));
        $this->out = (string)tempnam(sys_get_temp_dir(), 'wpm-transfer');
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
    }

    private function end(int $id, int $patience = 0): SiteTransfer
    {
        return new SiteTransfer($this->store, $this->crypto, $id, $patience, static function (): void {
        });
    }

    /** The source sends a description and two streams. */
    private function send(int $id, string $files = 'FILES', string $dump = 'DUMP'): void
    {
        $sender = $this->end($id);
        $runner = new Runner(false);
        $sender->sendMeta(['home' => 'http://old.example.com', 'prefix' => 'wp_']);
        $sender->sendStream($runner, new Command(['printf', '%s', $files]), SiteTransfer::FILES);
        $sender->sendStream($runner, new Command(['printf', '%s', $dump]), SiteTransfer::DATABASE);
        $sender->finish();
    }

    private function receiveAll(int $id): array
    {
        $receiver = $this->end($id);
        $runner = new Runner(false);
        $meta = $receiver->receiveMeta();
        $receiver->receiveStream($runner, new Command(['/bin/sh', '-c', 'cat > "$0"', $this->out]), SiteTransfer::FILES);
        $files = (string)file_get_contents($this->out);
        $receiver->receiveStream($runner, new Command(['/bin/sh', '-c', 'cat > "$0"', $this->out]), SiteTransfer::DATABASE);
        return [$meta, $files, (string)file_get_contents($this->out)];
    }

    public function testASiteTravelsWhole(): void
    {
        $id = $this->store->open(7, 9, 1, 2);
        $this->send($id, 'tar data', 'sql dump');

        self::assertSame([['home' => 'http://old.example.com', 'prefix' => 'wp_'], 'tar data', 'sql dump'], $this->receiveAll($id));
        self::assertSame(0, $this->store->pending($id), 'every piece is deleted once read');
    }

    public function testThePiecesCannotBeReadOnTheirWay(): void
    {
        $id = $this->store->open(7, 9, 1, 2);
        $this->send($id, 'secret file content', 'secret dump');
        foreach ($this->store->pieces[$id] as $piece) {
            self::assertStringNotContainsString('secret', $piece);
            self::assertStringNotContainsString('old.example.com', $piece);
        }
    }

    public function testAChangedPieceIsRefused(): void
    {
        $id = $this->store->open(7, 9, 1, 2);
        $this->send($id);
        $this->store->pieces[$id][1][30] = chr(ord($this->store->pieces[$id][1][30]) ^ 1);

        $this->expectExceptionMessage('cannot be decrypted');
        $this->receiveAll($id);
    }

    public function testAPieceOfAnotherTransferIsRefused(): void
    {
        $first = $this->store->open(7, 9, 1, 2);
        $second = $this->store->open(8, 10, 1, 2);
        $this->send($first, 'site one');
        $this->send($second, 'site two');
        $this->store->pieces[$second][1] = $this->store->pieces[$first][1];

        $this->expectExceptionMessage('out of place');
        $this->receiveAll($second);
    }

    public function testReorderedPiecesAreRefused(): void
    {
        $id = $this->store->open(7, 9, 1, 2);
        $this->send($id);
        [$this->store->pieces[$id][3], $this->store->pieces[$id][4]] = [$this->store->pieces[$id][4], $this->store->pieces[$id][3]];

        $this->expectExceptionMessage('out of place');
        $this->receiveAll($id);
    }

    // Without its end the dump may be cut halfway: never loaded as if it were whole.
    public function testACutStreamIsNotTakenForAWholeOne(): void
    {
        $id = $this->store->open(7, 9, 1, 2);
        $this->send($id);
        // Pieces: 0 description, 1 files, 2 end of the files, 3 dump, 4 end of the dump.
        unset($this->store->pieces[$id][4]);

        $this->expectExceptionMessage('did not send');
        $this->receiveAll($id);
    }

    public function testTheFailureOfTheSourceReachesTheTarget(): void
    {
        $id = $this->store->open(7, 9, 1, 2);
        $this->end($id)->fail('Copy of the files failed: disk full');

        $this->expectExceptionMessage('The source node failed: Copy of the files failed: disk full');
        $this->end($id, 5)->receiveMeta();
    }

    public function testTheSourceStopsWhenTheTargetGivesUp(): void
    {
        $id = $this->store->open(7, 9, 1, 2);
        for ($i = 0; $i < SiteTransfer::MAX_PENDING; $i++) {
            $this->store->put($id, 100 + $i, 'x');
        }
        $this->store->setState($id, 'failed', 'Creation of wp-config.php failed');

        $this->expectExceptionMessage('The target node stopped the transfer: Creation of wp-config.php failed');
        $this->end($id, 5)->sendMeta(['home' => 'x']);
    }

    public function testAFullStoreMakesTheSourceWait(): void
    {
        $id = $this->store->open(7, 9, 1, 2);
        for ($i = 0; $i < SiteTransfer::MAX_PENDING; $i++) {
            $this->store->put($id, 100 + $i, 'x');
        }
        $waits = 0;
        $sender = new SiteTransfer($this->store, $this->crypto, $id, 5, function () use (&$waits, $id): void {
            // The target reads one piece after the third wait.
            if (++$waits === 3) {
                $this->store->take($id, 100);
            }
        });
        $sender->sendMeta(['home' => 'x']);
        self::assertSame(3, $waits);
    }

    public function testAnotherKeyCannotReadTheTransfer(): void
    {
        $id = $this->store->open(7, 9, 1, 2);
        $this->send($id);
        $other = new SiteTransfer($this->store, new Crypto(base64_decode(Crypto::generateKey())), $id, 0, static function (): void {
        });

        $this->expectExceptionMessage('cannot be decrypted');
        $other->receiveMeta();
    }
}
