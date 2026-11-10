<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager\Tests;

use PHPUnit\Framework\TestCase;
use WpManager\Command;
use WpManager\Runner;

final class RunnerTest extends TestCase
{
    // Regression: a WP-CLI warning came first and filled the short message in the site list,
    // hiding the real error.
    public function testTheErrorComesBeforeTheWarnings(): void
    {
        $text = "Warning: Failed to create directory '/x/.wp-cli/cache/': mkdir(): Operation not permitted.\nError: Failed to get url";

        self::assertSame("Error: Failed to get url\nWarning: Failed to create directory '/x/.wp-cli/cache/': mkdir(): Operation not permitted.", Runner::failureText($text));
    }

    // Regression (VM): "No plugins installed." alone did not say which plugin, nor why.
    public function testAWarningThatExplainsTheErrorIsKept(): void
    {
        $text = "Warning: Couldn't find 'xyz' in the WordPress.org plugin directory.\nWarning: The 'xyz' plugin could not be found.\nError: No plugins installed.";

        self::assertStringStartsWith('Error: No plugins installed.', Runner::failureText($text));
        self::assertStringContainsString("Couldn't find 'xyz'", Runner::failureText($text));
    }

    public function testOnlyWarningsAreKept(): void
    {
        self::assertSame('Warning: something odd', Runner::failureText('Warning: something odd'));
    }

    public function testOtherOutputIsKept(): void
    {
        self::assertSame("sudo: unknown user\nsecond line", Runner::failureText("sudo: unknown user\nsecond line\n"));
    }

    public function testPipeCarriesTheDataBetweenTwoCommands(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wpm-pipe');
        $runner = new Runner(false);
        $runner->pipe(
            new Command(['/bin/sh', '-c', 'echo hello']),
            new Command(['/bin/sh', '-c', 'cat > ' . escapeshellarg($file)]),
        );

        self::assertSame("hello\n", file_get_contents($file));
        unlink($file);
    }

    public function testPipeCarriesMoreThanAPipeBuffer(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wpm-pipe');
        (new Runner(false))->pipe(
            new Command(['/bin/sh', '-c', 'head -c 3000000 /dev/zero']),
            new Command(['/bin/sh', '-c', 'cat > ' . escapeshellarg($file)]),
        );

        self::assertSame(3000000, filesize($file));
        unlink($file);
    }

    public function testPipeFailsWhenTheFirstCommandFails(): void
    {
        $this->expectExceptionMessageMatches('/Command failed \(4\): Error: source broken/');
        (new Runner(false))->pipe(
            new Command(['/bin/sh', '-c', 'echo "Error: source broken" >&2; exit 4']),
            new Command(['/bin/sh', '-c', 'cat > /dev/null']),
        );
    }

    public function testPipeFailsWhenTheSecondCommandFails(): void
    {
        $this->expectExceptionMessageMatches('/Command failed \(5\): target broken/');
        (new Runner(false))->pipe(
            new Command(['/bin/sh', '-c', 'echo data']),
            new Command(['/bin/sh', '-c', 'cat > /dev/null; echo "target broken" >&2; exit 5']),
        );
    }

    // Regression: a site could fill the memory of the root worker with endless output.
    public function testEndlessOutputIsStopped(): void
    {
        $this->expectExceptionMessage('Too much output');
        (new Runner(false, [], 10, 100000))->run(new Command(['/bin/sh', '-c', 'head -c 3000000 /dev/zero']));
    }

    // Regression: the limit was only checked while the process ran, and in CI one that wrote
    // everything and ended between two checks got past it.
    public function testTooMuchOutputIsStoppedAlsoIfTheProcessHasEnded(): void
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                (new Runner(false, [], 10, 1000))->run(new Command(['/bin/sh', '-c', 'head -c 2000 /dev/zero']));
                self::fail('The limit was ignored');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Too much output', $e->getMessage());
            }
        }
    }

    public function testEndlessErrorsInAPipeAreStopped(): void
    {
        $this->expectExceptionMessage('Too much output');
        (new Runner(false, [], 10, 100000))->pipe(
            new Command(['/bin/sh', '-c', 'head -c 3000000 /dev/zero >&2; sleep 5']),
            new Command(['/bin/sh', '-c', 'cat > /dev/null']),
        );
    }

    // Regression: a restore that mysql refused only reported "cat: write error: Broken pipe".
    public function testPipeNamesTheTargetBeforeTheBrokenPipeOfTheSource(): void
    {
        $this->expectExceptionMessageMatches('/Command failed \(6\): target refused\nbroken pipe/');
        (new Runner(false))->pipe(
            new Command(['/bin/sh', '-c', 'sleep 1; echo "broken pipe" >&2; exit 1']),
            new Command(['/bin/sh', '-c', 'echo "target refused" >&2; exit 6']),
        );
    }

    public function testPipeDoesNothingInDryRun(): void
    {
        $runner = new Runner(true);
        $runner->pipe(new Command(['false']), new Command(['false']));

        self::assertStringContainsString("[dry-run] 'false' | 'false'", implode("\n", $runner->log()));
    }

    public function testMustRunReportsTheRealErrorWithoutSecrets(): void
    {
        $runner = new Runner(false, ['S3cret']);
        $this->expectExceptionMessageMatches('/^Command failed \(3\): Error: bad \*{8}\nWarning: noise$/');
        $runner->mustRun(new Command(['/bin/sh', '-c', 'echo "Warning: noise" >&2; echo "Error: bad S3cret" >&2; exit 3']));
    }

    private static function gone(int $pid): bool
    {
        $state = trim((string)shell_exec('ps -o stat= -p ' . $pid));
        return $state === '' || str_starts_with($state, 'Z');
    }

    // Found in the VM: a site can ignore SIGTERM, and the sudo waiting for it kept the lock of the worker.
    public function testACommandThatIgnoresSigtermIsKilledAfterTheTimeout(): void
    {
        $pidFile = tempnam(sys_get_temp_dir(), 'wpm');
        $start = microtime(true);
        try {
            (new Runner(false, [], 1, 100000, 1))->run(new Command(['/bin/sh', '-c', 'trap "" TERM; echo $$ > ' . $pidFile . '; while :; do sleep 1; done']));
            self::fail('No timeout');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Timeout: ', $e->getMessage());
        }
        $pid = (int)file_get_contents($pidFile);
        unlink($pidFile);
        self::assertGreaterThan(0, $pid);
        self::assertTrue(self::gone($pid), 'still running after the grace time');
        self::assertLessThan(8, microtime(true) - $start);
    }

    public function testWhatTheCommandStartedIsKilledToo(): void
    {
        if (!is_dir('/proc/self')) {
            self::markTestSkipped('needs /proc (Linux)');
        }
        $pidFile = tempnam(sys_get_temp_dir(), 'wpm');
        try {
            (new Runner(false, [], 1, 100000, 1))->run(new Command(['/bin/sh', '-c', 'trap "" TERM; sleep 30 & echo $! > ' . $pidFile . '; wait']));
            self::fail('No timeout');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Timeout: ', $e->getMessage());
        }
        $pid = (int)file_get_contents($pidFile);
        unlink($pidFile);
        self::assertGreaterThan(0, $pid);
        usleep(200_000);
        self::assertTrue(self::gone($pid), 'the child of the command is still running');
    }

    public function testBothSidesOfAPipeAreKilledAfterTheTimeout(): void
    {
        $pidFile = tempnam(sys_get_temp_dir(), 'wpm');
        try {
            (new Runner(false, [], 1, 100000, 1))->pipe(
                new Command(['/bin/sh', '-c', 'trap "" TERM; echo $$ > ' . $pidFile . '; while :; do sleep 1; done']),
                new Command(['/bin/sh', '-c', 'trap "" TERM; cat > /dev/null']),
            );
            self::fail('No timeout');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Timeout: ', $e->getMessage());
        }
        $pid = (int)file_get_contents($pidFile);
        unlink($pidFile);
        self::assertTrue(self::gone($pid), 'the source is still running');
    }

    public function testACommandThatStopsOnSigtermIsNotKept(): void
    {
        $start = microtime(true);
        try {
            (new Runner(false, [], 1, 100000, 30))->run(new Command(['/bin/sh', '-c', 'exec sleep 30']));
            self::fail('No timeout');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Timeout: ', $e->getMessage());
        }
        // The grace time ends as soon as the command is gone.
        self::assertLessThan(5, microtime(true) - $start);
    }
}
