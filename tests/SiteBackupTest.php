<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Config;
use WpManager\PathGuard;
use WpManager\Runner;
use WpManager\SiteBackup;

final class SiteBackupTest extends TestCase
{
    private string $root;
    private string $site;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wpm-bak-' . bin2hex(random_bytes(4));
        mkdir($base);
        $this->root = (string)realpath($base);
        $this->site = $this->root . '/client1/web7';
        mkdir($this->site . '/web', 0o755, true);
        mkdir($this->site . '/tmp');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function backups(): SiteBackup
    {
        return new SiteBackup(Config::fromArray([]), new PathGuard($this->root));
    }

    private function record(): array
    {
        return ['document_root' => $this->site, 'system_user' => 'web7'];
    }

    private function fakeBackup(string $id, bool $complete = true): void
    {
        $dir = "{$this->site}/tmp/wpmanager-backups/{$id}";
        mkdir($dir, 0o700, true);
        file_put_contents("{$dir}/files.tar.gz", str_repeat('x', 10));
        file_put_contents("{$dir}/db.sql.gz", str_repeat('y', 5));
        if ($complete) {
            touch("{$dir}/COMPLETE");
        }
    }

    public function testBackupIsVerifiedAndMarkedCompleteLast(): void
    {
        $runner = new Runner(true);
        $id = $this->backups()->create($this->record(), $runner);
        $log = implode("\n", $runner->log());

        self::assertMatchesRegularExpression('/^\d{8}-\d{6}$/', $id);
        $order = ["'db' 'export'", "'gzip'", "'tar' '-czf'", "'gzip' '-t'", "'tar' '-tzf'", "COMPLETE"];
        $last = -1;
        foreach ($order as $step) {
            $at = strpos($log, $step);
            self::assertNotFalse($at, $step);
            self::assertGreaterThan($last, $at, $step);
            $last = $at;
        }
    }

    public function testEveryCommandRunsAsTheSiteUser(): void
    {
        $runner = new Runner(true);
        $this->fakeBackup('20260101-000000');
        $backups = $this->backups();
        $backups->create($this->record(), $runner);
        $backups->restore($this->record(), '20260101-000000', $runner);

        foreach ($runner->log() as $line) {
            self::assertStringContainsString("'sudo' '-u' 'web7'", $line);
        }
    }

    public function testBackupsLiveWhereIspconfigLeavesThemAlone(): void
    {
        self::assertSame("{$this->site}/tmp/wpmanager-backups", $this->backups()->directory($this->record()));
    }

    public function testListShowsCompleteBackupsNewestFirst(): void
    {
        $this->fakeBackup('20260101-000000');
        $this->fakeBackup('20260103-000000');
        $this->fakeBackup('20260102-000000', false);

        $list = $this->backups()->list($this->record());

        self::assertSame(['20260103-000000', '20260101-000000'], array_column($list, 'id'));
        self::assertSame(15, $list[0]['size']);
    }

    public function testListIgnoresForeignEntriesAndLinks(): void
    {
        $this->fakeBackup('20260101-000000');
        $dir = "{$this->site}/tmp/wpmanager-backups";
        mkdir("{$dir}/not-a-backup");
        symlink('/etc', "{$dir}/20260102-000000");

        self::assertSame(['20260101-000000'], array_column($this->backups()->list($this->record()), 'id'));
    }

    public function testNoBackupsYet(): void
    {
        self::assertSame([], $this->backups()->list($this->record()));
    }

    public function testOldBackupsAreRemovedKeepingTheNewest(): void
    {
        foreach (['20260101-000000', '20260102-000000', '20260103-000000', '20260104-000000'] as $id) {
            $this->fakeBackup($id);
        }
        $runner = new Runner(true);
        $this->backups()->prune($this->record(), $runner);
        $log = implode("\n", $runner->log());

        self::assertStringContainsString('20260101-000000', $log);
        self::assertStringNotContainsString('20260102-000000', $log);
        self::assertStringContainsString("'rm' '-rf'", $log);
    }

    public function testAnIncompleteBackupIsCleanedOnlyOnceItIsOld(): void
    {
        $this->fakeBackup('20260101-000000', false);
        $this->fakeBackup('20260102-000000');
        $runner = new Runner(true);
        $this->backups()->prune($this->record(), $runner);

        self::assertStringContainsString('20260101-000000', implode("\n", $runner->log()));
    }

    public function testRestoreChecksEverythingBeforeTouchingTheSite(): void
    {
        $this->fakeBackup('20260101-000000');
        $runner = new Runner(true);
        $this->backups()->restore($this->record(), '20260101-000000', $runner);
        $log = implode("\n", $runner->log());

        $order = ["'gzip' '-t'", "'tar' '-tzf'", "'tar' '-xzf'", "'rsync'", "'db' 'import'"];
        $last = -1;
        foreach ($order as $step) {
            $at = strpos($log, $step);
            self::assertNotFalse($at, $step);
            self::assertGreaterThan($last, $at, $step);
            $last = $at;
        }
    }

    public function testUploadsAreInTheBackupByDefault(): void
    {
        $runner = new Runner(true);
        $this->backups()->create($this->record(), $runner);
        $log = implode("\n", $runner->log());

        self::assertStringNotContainsString('wp-content/uploads', $log);
        self::assertStringNotContainsString('NO_UPLOADS', $log);
    }

    public function testUploadsCanBeLeftOutAndTheBackupSaysSo(): void
    {
        $runner = new Runner(true);
        $this->backups()->create($this->record() + ['backup_uploads' => 'n'], $runner);
        $log = implode("\n", $runner->log());

        self::assertStringContainsString("'--exclude=./wp-content/uploads'", $log);
        self::assertLessThan(strpos($log, '/COMPLETE'), strpos($log, '/NO_UPLOADS'));
    }

    // Without the exclude, rsync --delete would wipe uploads that are not in the backup.
    public function testRestoreOfABackupWithoutUploadsLeavesThemAlone(): void
    {
        $this->fakeBackup('20260101-000000');
        touch("{$this->site}/tmp/wpmanager-backups/20260101-000000/NO_UPLOADS");
        $runner = new Runner(true);
        $this->backups()->restore($this->record(), '20260101-000000', $runner);

        self::assertStringContainsString("'--exclude=/wp-content/uploads'", implode("\n", $runner->log()));
    }

    // The backup decides, not the current option: a full backup must restore the uploads too.
    public function testRestoreFollowsTheBackupNotTheCurrentOption(): void
    {
        $this->fakeBackup('20260101-000000');
        $runner = new Runner(true);
        $this->backups()->restore($this->record() + ['backup_uploads' => 'n'], '20260101-000000', $runner);

        self::assertStringNotContainsString('--exclude', implode("\n", $runner->log()));
    }

    public function testListSaysWhichBackupsHaveTheUploads(): void
    {
        $this->fakeBackup('20260101-000000');
        $this->fakeBackup('20260102-000000');
        touch("{$this->site}/tmp/wpmanager-backups/20260102-000000/NO_UPLOADS");

        $list = $this->backups()->list($this->record());

        self::assertFalse($list[0]['uploads']);
        self::assertTrue($list[1]['uploads']);
    }

    public function testRestoreRefusesAnIncompleteBackup(): void
    {
        $this->fakeBackup('20260101-000000', false);
        $this->expectExceptionMessage('not a complete backup');
        $this->backups()->restore($this->record(), '20260101-000000', new Runner(true));
    }

    /** @dataProvider badIds */
    public function testRestoreRefusesABadId(string $id): void
    {
        $this->expectExceptionMessage('Invalid backup');
        $this->backups()->restore($this->record(), $id, new Runner(true));
    }

    public static function badIds(): array
    {
        return [['../../etc'], ['20260101-000000/../x'], [''], ['20260101']];
    }

    public function testASiteOutsideTheRootIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->backups()->create(['document_root' => '/etc', 'system_user' => 'web7'], new Runner(true));
    }
}
